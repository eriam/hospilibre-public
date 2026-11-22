<?php

/**
 * @package Hospilibre\PlanningOp
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Hospilibre\PlanningOp\Tests\Unit;

use Exception;
use Hospilibre\Hospi\CAffectation;
use Hospilibre\Patients\CPatient;
use Hospilibre\PlanningOp\COperation;
use Hospilibre\PlanningOp\CSejour;
use Ox\Cli\Seed\BulkWriter;
use Ox\Cli\Seed\OrmWriter;
use Ox\Cli\Seed\Referential;
use Ox\Cli\Seed\ReferentialBuilder;
use Ox\Cli\Seed\SeedContext;
use Ox\Cli\Seed\SeedProfile;
use Ox\Cli\Seed\SeedPurger;
use Ox\Cli\Seed\SeedRandom;
use Ox\Core\CSQLDataSource;
use Ox\Core\CStoredObject;
use Ox\Tests\UnitTestHospilibre;
use Throwable;

/**
 * Le garde-fou du chemin d'écriture par lots.
 *
 * Le mode par lots contourne `check()`, les déclencheurs et les journaux : c'est un risque assumé,
 * mais il doit être BORNÉ. Ce test écrit les mêmes entités logiques par les deux chemins et compare
 * les lignes obtenues colonne par colonne. Toute divergence non explicitement autorisée échoue.
 *
 * Sans ce test, le mode par lots serait un chemin « au jugé » : il produirait des données
 * plausibles en apparence, avec des colonnes dérivées vides que personne ne remarquerait avant
 * qu'un écran de recherche ne rende rien.
 */
class SeedBulkEquivalenceTest extends UnitTestHospilibre
{
    private const NOW = '2026-06-15 09:00:00';

    /** Marqueur propre au test, pour que la purge ne touche que ses données. */
    private const MARKER = self::class;

    /**
     * Colonnes dont la divergence est LÉGITIME, avec la raison.
     *
     * La liste est volontairement courte : chaque entrée est une renonciation à une garantie.
     */
    private const ALLOWED_DIVERGENCES = [
        // Les identifiants diffèrent par construction : l'ORM laisse l'AUTO_INCREMENT décider, le
        // chemin par lots attribue une plage réservée.
        'patient_id',
        'sejour_id',
        'affectation_id',
        'operation_id',
    ];

    /** @var Referential|null */
    private static $referential;

    /** @var SeedContext|null */
    private static $context;

    /**
     * @var Throwable|null Échec de construction du référentiel, retenu et relancé tel quel.
     *
     * Sans cela, chaque méthode relançait une construction : la première échouait sur la vraie
     * cause, les suivantes sur une collision de nom d'utilisateur avec les objets laissés par la
     * tentative précédente, et l'erreur utile disparaissait sous les erreurs dérivées.
     */
    private static $referential_failure;

    public static function tearDownAfterClass(): void
    {
        try {
            $purger = new SeedPurger(self::MARKER);
            $purger->purgeAll();
            $purger->assertClean();
        } catch (Throwable $t) {
            // La purge est vérifiée par ailleurs ; ne pas masquer un échec de test par un échec
            // de nettoyage.
        }

        self::$referential         = null;
        self::$context             = null;
        self::$referential_failure = null;

        parent::tearDownAfterClass();
    }

    /**
     * Les patients NE PASSENT PAS par le chemin par lots, et c'est une décision que ce test fige.
     *
     * Ce même test, quand CPatient y figurait, a relevé quatre divergences : `creator_id`,
     * `creation_date`, `status` et `source_identite_id`. Toutes proviennent de
     * `CPatient::store()` lui-même, qui crée au passage une source d'identité. Un chemin générique
     * ne peut pas les reproduire, et un patient sans source d'identité a l'air correct tout en
     * étant inexploitable sur les écrans d'identité.
     */
    public function testModelsWhoseStoreFillsColumnsAreExcludedFromTheBulkPath(): void
    {
        // Relevé par ce test lui-même, quand ces classes y figuraient : chaque store() remplit des
        // colonnes qu'un chemin générique ne sait pas reproduire.
        $excluded = [
            'CPatient'   => 'creator_id, creation_date, status, source_identite_id',
            'CSejour'    => 'recuse, type_pec',
            'COperation' => 'duree_preop',
        ];

        foreach ($excluded as $class => $columns) {
            $this->assertNotContains(
                $class,
                BulkWriter::MASS_CLASSES,
                "Écrire {$class} par lots laisserait vides : {$columns}"
            );
        }
    }

    /**
     * @throws Throwable
     */
    public function testAffectationRowsAreEquivalent(): void
    {
        $referential = $this->referential();
        $slug        = $referential->serviceSlugs()[0];
        $sejour      = $this->anchorSejour();
        $bed         = $referential->beds($slug)[0];

        $this->assertEquivalent(
            CAffectation::class,
            function () use ($referential, $slug, $sejour, $bed): CAffectation {
                $affectation             = new CAffectation();
                $affectation->sejour_id  = $sejour->_id;
                $affectation->service_id = $referential->service($slug)->_id;
                $affectation->lit_id     = $bed->_id;
                $affectation->entree       = '2026-06-01 08:00:00';
                $affectation->sortie       = '2026-06-04 10:00:00';
                $affectation->effectue     = '1';
                // Comme le fait le builder : sans quoi l'ORM le déduirait du séjour et le chemin
                // par lots le laisserait vide.
                $affectation->praticien_id = $sejour->praticien_id;

                return $affectation;
            }
        );
    }

    /**
     * Le chemin par lots doit RÉSERVER ses identifiants, pas espérer la contiguïté des
     * AUTO_INCREMENT : l'identifiant doit être connu dès l'écriture, sans quoi aucun enfant ne peut
     * être rattaché.
     *
     * @throws Throwable
     */
    public function testBulkAssignsIdentifiersBeforeInsertion(): void
    {
        $writer = new BulkWriter(self::MARKER);

        $first  = $this->makeAffectation('2026-06-20 08:00:00', '2026-06-21 08:00:00');
        $second = $this->makeAffectation('2026-06-21 08:00:00', '2026-06-22 08:00:00');

        $writer->write($first);
        $writer->write($second);

        $this->assertNotEmpty($first->_id, "L'identifiant doit être connu avant l'insertion");
        $this->assertSame((int)$first->_id + 1, (int)$second->_id, 'Les identifiants doivent être consécutifs');

        // Rien n'est encore en base : les lignes sont en tampon.
        $this->assertNull($this->readRow('affectation', 'affectation_id', (int)$first->_id));

        $writer->flush();

        $this->assertNotNull($this->readRow('affectation', 'affectation_id', (int)$first->_id));
        $this->assertNotNull($this->readRow('affectation', 'affectation_id', (int)$second->_id));

        $ranges = $writer->reservedRanges();
        $this->assertArrayHasKey('affectation', $ranges);
        $this->assertSame((int)$first->_id, $ranges['affectation']['from']);
        $this->assertSame((int)$second->_id, $ranges['affectation']['to']);
        $this->assertSame('CAffectation', $ranges['affectation']['class']);
    }

    /**
     * Une étiquette suppose une trace individuelle, que ce chemin ne produit pas : mieux vaut
     * refuser que promettre une citabilité qu'on ne tiendra pas.
     *
     * @throws Throwable
     */
    public function testBulkRefusesToTagAMassEntity(): void
    {
        $writer = new BulkWriter(self::MARKER);

        $this->expectExceptionMessageMatches('/ne peut pas porter l\'étiquette/');
        $writer->write($this->makeAffectation('2026-06-25 08:00:00', '2026-06-26 08:00:00'), 'affectation:vedette');
    }

    /**
     * Le référentiel doit rester sur l'ORM même en mode par lots : c'est ce qui garde les deux
     * modes cohérents, et ce qui laisse le référentiel purgeable objet par objet.
     */
    public function testReferentialClassesStayOnTheOrmPath(): void
    {
        $referential = ['CGroups', 'CService', 'CChambre', 'CLit', 'CSalle', 'CPlageOp', 'CMediusers'];

        foreach ($referential as $class) {
            $this->assertNotContains(
                $class,
                BulkWriter::MASS_CLASSES,
                "{$class} appartient au référentiel et doit passer par l'ORM"
            );
        }

        $this->assertSame(['CAffectation'], BulkWriter::MASS_CLASSES);
    }

    /**
     * Écrit la même entité logique par les deux chemins et compare les lignes obtenues.
     *
     * @param callable():CStoredObject $factory
     *
     * @throws Throwable
     */
    private function assertEquivalent(string $class, callable $factory): void
    {
        $orm_object = $factory();
        (new OrmWriter(self::MARKER))->write($orm_object);

        $bulk_writer = new BulkWriter(self::MARKER);
        $bulk_object = $factory();
        $bulk_writer->write($bulk_object);
        $bulk_writer->flush();

        $table = $orm_object->_spec->table;
        $key   = $orm_object->_spec->key;

        $orm_row  = $this->readRow($table, $key, (int)$orm_object->_id);
        $bulk_row = $this->readRow($table, $key, (int)$bulk_object->_id);

        $this->assertNotNull($orm_row, "La ligne écrite par l'ORM est introuvable");
        $this->assertNotNull($bulk_row, 'La ligne écrite par lots est introuvable');

        $divergences = [];
        foreach ($orm_row as $column => $value) {
            if (in_array($column, self::ALLOWED_DIVERGENCES, true)) {
                continue;
            }

            $bulk_value = $bulk_row[$column] ?? null;

            if ((string)$value !== (string)$bulk_value) {
                $divergences[] = sprintf(
                    '%s : ORM=%s / lots=%s',
                    $column,
                    var_export($value, true),
                    var_export($bulk_value, true)
                );
            }
        }

        $this->assertSame(
            [],
            $divergences,
            "Divergence entre les deux chemins d'écriture sur {$class} :\n  "
            . implode("\n  ", $divergences)
        );
    }

    /**
     * Affectation minimale mais valide, ancrée sur le référentiel et le séjour du test.
     *
     * @throws Throwable
     */
    private function makeAffectation(string $from, string $to): CAffectation
    {
        $referential = $this->referential();
        $slug        = $referential->serviceSlugs()[0];

        $affectation             = new CAffectation();
        $affectation->sejour_id  = $this->anchorSejour()->_id;
        $affectation->service_id = $referential->service($slug)->_id;
        $affectation->entree       = $from;
        $affectation->sortie       = $to;
        $affectation->effectue     = '1';
        $affectation->praticien_id = $this->anchorSejour()->praticien_id;

        return $affectation;
    }

    /**
     * Séjour minimal mais valide, ancré sur le référentiel du test.
     *
     * @throws Throwable
     */
    private function makeSejour(string $from, string $to): CSejour
    {
        $referential = $this->referential();
        $slug        = $referential->serviceSlugs()[0];

        $sejour                = new CSejour();
        $sejour->patient_id    = $this->anchorPatient()->_id;
        $sejour->praticien_id  = $referential->practitioners($slug)[0]->_id;
        $sejour->group_id      = $referential->group()->_id;
        $sejour->service_id    = $referential->service($slug)->_id;
        $sejour->type          = 'comp';
        $sejour->modalite      = 'libre';
        $sejour->chambre_seule = '0';
        $sejour->entree_prevue = $from;
        $sejour->sortie_prevue = $to;
        $sejour->entree_reelle = $from;
        $sejour->sortie_reelle = $to;
        $sejour->facturable    = '1';

        return $sejour;
    }

    private function makePatient(string $name): CPatient
    {
        $patient                  = new CPatient();
        $patient->nom             = $name;
        $patient->nom_jeune_fille = $name;
        $patient->prenom          = 'Test';
        $patient->naissance       = '1980-01-01';
        $patient->sexe            = 'm';

        return $patient;
    }

    /**
     * Patient servant d'ancrage aux séjours comparés : le même pour les deux chemins, pour que
     * l'entrée logique soit identique.
     *
     * @throws Throwable
     */
    private function anchorPatient(): CPatient
    {
        static $patient = null;

        if ($patient === null) {
            $patient = $this->makePatient('ANCRAGE');
            (new OrmWriter(self::MARKER))->write($patient);
        }

        return $patient;
    }

    /**
     * @throws Throwable
     */
    private function anchorSejour(): CSejour
    {
        static $sejour = null;

        if ($sejour === null) {
            $referential = $this->referential();
            $slug        = $referential->serviceSlugs()[0];

            $sejour                = new CSejour();
            $sejour->patient_id    = $this->anchorPatient()->_id;
            $sejour->praticien_id  = $referential->practitioners($slug)[0]->_id;
            $sejour->group_id      = $referential->group()->_id;
            $sejour->service_id    = $referential->service($slug)->_id;
            $sejour->type          = 'comp';
            $sejour->modalite      = 'libre';
            $sejour->chambre_seule = '0';
            $sejour->entree_prevue = '2026-06-10 08:00:00';
            $sejour->sortie_prevue = '2026-06-12 10:00:00';
            $sejour->entree_reelle = '2026-06-10 08:00:00';
            $sejour->sortie_reelle = '2026-06-12 10:00:00';
            $sejour->facturable    = '1';

            (new OrmWriter(self::MARKER))->write($sejour);
        }

        return $sejour;
    }

    /**
     * Référentiel minimal, construit une fois : un service, deux lits, un bloc.
     *
     * @throws Throwable
     */
    private function referential(): Referential
    {
        if (self::$referential_failure !== null) {
            throw self::$referential_failure;
        }

        if (self::$referential === null) {
            self::$context = new SeedContext(
                SeedProfile::named(
                    'small',
                    // QUATRE unités et non deux : le catalogue commence par les urgences et l'UHCD,
                    // si bien qu'un profil à deux services n'a aucune spécialité chirurgicale — et le
                    // bloc refuse de se construire, à juste titre.
                    ['services' => 4, 'beds' => 6, 'patients' => 2, 'sejours' => 2, 'window' => 1]
                ),
                new SeedRandom(31337),
                new OrmWriter(self::MARKER),
                self::NOW
            );

            try {
                self::$referential = (new ReferentialBuilder(self::$context))->build();
            } catch (Throwable $t) {
                self::$referential_failure = $t;

                throw $t;
            }
        }

        return self::$referential;
    }

    /**
     * @return array<string, string|null>|null
     * @throws Throwable
     */
    private function readRow(string $table, string $key, int $id): ?array
    {
        $ds  = CSQLDataSource::get('std');
        $row = $ds->loadHash($ds->prepare("SELECT * FROM `{$table}` WHERE `{$key}` = %1", $id));

        return is_array($row) && $row !== [] ? $row : null;
    }
}
