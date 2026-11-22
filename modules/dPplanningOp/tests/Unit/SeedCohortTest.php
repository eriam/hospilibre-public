<?php

/**
 * @package Hospilibre\PlanningOp
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Hospilibre\PlanningOp\Tests\Unit;

use Exception;
use Hospilibre\Patients\CPatient;
use Hospilibre\Hospi\CService;
use Hospilibre\PlanningOp\CSejour;
use Hospilibre\Sante400\CIdSante400;
use Ox\Cli\Seed\AnchoredCohort;
use Ox\Cli\Seed\CohortExport;
use Ox\Cli\Seed\HospiSeeder;
use Ox\Cli\Seed\IppRegistry;
use Ox\Cli\Seed\OrmWriter;
use Ox\Cli\Seed\SeedContext;
use Ox\Cli\Seed\SeedProfile;
use Ox\Cli\Seed\SeedPurger;
use Ox\Cli\Seed\SeedRandom;
use Ox\Core\CSQLDataSource;
use Ox\Cli\Seed\SeedReport;
use Ox\Tests\UnitTestHospilibre;
use Throwable;

/**
 * Ce qu'une cohorte semée doit garantir à un système EXTÉRIEUR, vérifié en relisant la base.
 *
 * Trois promesses sont tenues ici, et elles n'ont d'intérêt qu'ensemble :
 *
 * 1. **Chaque patient porte un IPP**, unique, déterministe. C'est le seul identifiant par lequel un
 *    laboratoire, un PACS ou un partage de fichiers peut désigner quelqu'un — un dossier sans IPP
 *    est invisible de l'extérieur, et un SIH qui n'en pose pas n'est pas crédible.
 * 2. **Les identités imposées entrent comme les autres** : avec un séjour, un service, une durée.
 *    Une identité sans parcours ferait du dossier que le scénario cite le plus vide de
 *    l'établissement, et le contraste le désignerait mieux qu'un surligneur.
 * 3. **Les homonymes survivent.** Deux patients de même nom, prénom, naissance et sexe ne sont pas
 *    un doublon à fusionner : c'est souvent l'épreuve elle-même, le rapprochement par nom devant
 *    échouer visiblement pour que le lien se fasse par l'IPP.
 *
 * Toutes les vérifications repartent de requêtes, jamais de l'état mémoire des builders : un
 * builder qui se croit cohérent n'apporte aucune garantie.
 *
 * Le jeu de données est construit UNE FOIS pour toute la classe — voir SeedSejourTest pour le même
 * arbitrage et la même échelle réduite.
 */
class SeedCohortTest extends UnitTestHospilibre
{
    private const NOW = '2026-06-15 09:00:00';

    private const SEED = 4242;

    /** Même échelle réduite que SeedSejourTest : dix unités, quarante lits, un mois. */
    private const SCALE = ['services' => 10, 'beds' => 40, 'window' => 1];

    /** Préfixe et départ distincts de ceux par défaut : le test vérifie qu'ils sont respectés. */
    private const IPP_PREFIX = 'TST-';

    private const IPP_START = 9000001;

    /**
     * Ancres. Les services cités appartiennent aux DIX PREMIÈRES unités du catalogue, seules
     * ouvertes à cette échelle ; en citer une autre ferait échouer le semis, ce qui est le
     * comportement voulu mais n'est pas ce qu'on teste ici.
     */
    private const ANCHORS = [
        ['TST-0000101', 'MARTIN', 'Sophie', '1979-03-14', 'f', 'cardiologie', 'Douleur thoracique'],
        ['TST-0000102', 'BERNARD', 'Ahmed', '1951-11-02', 'm', 'pneumologie', 'Décompensation BPCO'],
        ['TST-0000103', 'NGUYEN', 'Thi', '1996-02-11', 'f', '', ''],
        // Les deux homonymes, délibérés : mêmes traits stricts, IPP différents.
        ['TST-0000161', 'LEROY', 'Jean', '1962-05-08', 'm', 'gastro-enterologie', 'Hémorragie digestive'],
        ['TST-0000168', 'LEROY', 'Jean', '1962-05-08', 'm', 'medecine-interne', 'Bilan d\'anémie'],
    ];

    /** @var SeedReport|null */
    private static $report;

    /** @var Throwable|null */
    private static $failure;

    /** @var string|null */
    private static $anchors_file;

    /** @var string|null */
    private static $export_file;

    /** @var IppRegistry|null */
    private static $registry;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Le tag d'IPP est posé EN MÉMOIRE et non dans includes/config.php : un test ne doit pas
        // modifier la configuration de l'instance qui l'héberge. `CAppUI::conf()` lit ce global.
        $GLOBALS['dPconfig']['dPpatients']['CPatient']['tag_ipp'] = IppRegistry::DEFAULT_TAG;
    }

    public static function tearDownAfterClass(): void
    {
        self::purgeSeed();

        foreach ([self::$anchors_file, self::$export_file] as $file) {
            if ($file !== null) {
                @unlink($file);
            }
        }

        self::$report       = null;
        self::$failure      = null;
        self::$registry     = null;
        self::$anchors_file = null;
        self::$export_file  = null;

        parent::tearDownAfterClass();
    }

    /**
     * Les ancrés sont COMPTÉS dans le total, pas ajoutés par-dessus.
     *
     * `--patients=N` doit produire N patients, dont les ancrés. Les ajouter ferait mentir le
     * dimensionnement, et l'occupation calculée avec lui aussi.
     *
     * @throws Exception
     */
    public function testAnchorsAreCountedWithinTheRequestedTotal(): void
    {
        $report = $this->report();

        $this->assertSame(self::profile()->patients(), $report->patients());
        $this->assertGreaterThan(count(self::ANCHORS), $report->patients());
    }

    /**
     * LA PROMESSE CENTRALE DE L'ANCRAGE : aucun ancré ne reste sans séjour.
     *
     * @throws Exception
     */
    public function testEveryAnchorGetsAStay(): void
    {
        $this->assertFalse($this->report()->hasUnplacedAnchors());
        $this->assertSame([], $this->report()->unplacedAnchors());

        foreach (self::ANCHORS as [$ipp]) {
            $patient = $this->patientByIpp($ipp);

            $sejour             = new CSejour();
            $sejour->patient_id = $patient->_id;

            $this->assertNotEmpty(
                $sejour->loadMatchingList('sejour_id'),
                "L'ancré {$ipp} doit avoir au moins un séjour"
            );
        }
    }

    /**
     * L'identité imposée arrive au socle TELLE QUELLE, et n'est modifiée que par sa normalisation.
     *
     * LA CASSE N'EST PAS CELLE DU FICHIER, et ce n'est pas un défaut. `CPatient::store()` passe les
     * traits stricts par `applyModeIdentitoVigilance()`, dont le mode par défaut — `strict` — met
     * TOUT en capitales, prénom compris : il reçoit bien un drapeau `$firstname`, mais ce mode
     * l'ignore. C'est la règle française d'identito-vigilance, et un SIH qui ne l'appliquerait pas
     * serait le fautif. Conséquence pour un système extérieur : « Sophie » devient « SOPHIE », donc
     * un rapprochement sur le nom doit être insensible à la casse — raison de plus de le faire sur
     * l'IPP.
     *
     * L'assertion passe par la fonction DU SOCLE plutôt que par une casse recopiée : elle vérifie
     * ainsi que le seed n'a rien altéré de son côté, et elle suivra le socle si le mode change.
     *
     * @throws Exception
     */
    public function testImposedIdentitiesReachTheSocleUnaltered(): void
    {
        $this->report();

        foreach (self::ANCHORS as [$ipp, $nom, $prenom, $naissance, $sexe]) {
            $patient = $this->patientByIpp($ipp);

            $this->assertSame(
                CPatient::applyModeIdentitoVigilance(mb_strtoupper($nom)),
                $patient->nom,
                "Nom de {$ipp}"
            );
            $this->assertSame(
                CPatient::applyModeIdentitoVigilance($prenom, true),
                $patient->prenom,
                "Prénom de {$ipp}"
            );
            $this->assertSame($naissance, $patient->naissance, "Naissance de {$ipp}");
            $this->assertSame($sexe, $patient->sexe, "Sexe de {$ipp}");
        }
    }

    /**
     * LE CAS QUI COMPTE. Deux homonymes stricts restent deux patients distincts, avec deux IPP.
     *
     * @throws Exception
     */
    public function testStrictHomonymsRemainTwoDistinctPatients(): void
    {
        $this->report();

        $first  = $this->patientByIpp('TST-0000161');
        $second = $this->patientByIpp('TST-0000168');

        $this->assertNotSame((int)$first->_id, (int)$second->_id);
        $this->assertSame($first->nom, $second->nom);
        $this->assertSame($first->prenom, $second->prenom);
        $this->assertSame($first->naissance, $second->naissance);
    }

    /**
     * Un service imposé est respecté sur le PREMIER séjour de l'ancré.
     *
     * Le premier, et non tous : un ancré reversé dans la population générale peut obtenir d'autres
     * séjours ailleurs, ce qui est voulu — il vit dans l'hôpital comme les autres.
     *
     * @throws Exception
     */
    public function testAnImposedServiceLandsOnTheFirstStay(): void
    {
        $this->report();

        foreach (self::ANCHORS as [$ipp, , , , , $service, $motif]) {
            if ($service === '') {
                continue;
            }

            $sejour = $this->firstStayOf($this->patientByIpp($ipp));

            $this->assertSame(
                $service,
                $this->slugOfService((int)$sejour->service_id),
                "Service du premier séjour de {$ipp}"
            );

            if ($motif !== '') {
                $this->assertSame($motif, $sejour->libelle, "Motif du premier séjour de {$ipp}");
            }
        }
    }

    /**
     * Chaque patient semé porte EXACTEMENT un IPP, et tous sont distincts.
     *
     * @throws Exception
     */
    public function testEveryPatientCarriesExactlyOneUniqueIpp(): void
    {
        $report = $this->report();
        $tag    = CPatient::getTagIPP($report->referential()->group()->_id);

        $this->assertNotEmpty($tag, 'Le tag d\'IPP doit être résolu');

        $idex      = new CIdSante400();
        $idex->tag = $tag;
        $idexes    = $idex->loadMatchingList('id_sante400_id');

        $this->assertCount($report->patients(), $idexes, 'Un IPP par patient semé');

        // Le registre et la base doivent dire la même chose : un registre qui compte des IPP que la
        // base n'a pas signalerait une écriture avalée.
        $this->assertSame($report->patients(), self::$registry->count());

        $numbers  = [];
        $patients = [];

        foreach ($idexes as $_idex) {
            $this->assertSame('CPatient', $_idex->object_class);

            $numbers[$_idex->id400]        = true;
            $patients[$_idex->object_id]   = true;
        }

        $this->assertCount($report->patients(), $numbers, 'Les IPP doivent être deux à deux distincts');
        $this->assertCount($report->patients(), $patients, 'Aucun patient ne doit porter deux IPP');
    }

    /**
     * La masse numérote à partir du départ demandé, et n'empiète pas sur les IPP imposés.
     *
     * @throws Exception
     */
    public function testTheMassNumbersFromTheRequestedStartAndSkipsImposedNumbers(): void
    {
        $report = $this->report();
        $tag    = CPatient::getTagIPP($report->referential()->group()->_id);

        $idex      = new CIdSante400();
        $idex->tag = $tag;

        $mass    = 0;
        $imposed = array_column(self::ANCHORS, 0);

        foreach ($idex->loadMatchingList('id_sante400_id') as $_idex) {
            if (in_array($_idex->id400, $imposed, true)) {
                continue;
            }

            $this->assertStringStartsWith(self::IPP_PREFIX, $_idex->id400);

            $number = (int)substr($_idex->id400, strlen(self::IPP_PREFIX));
            $this->assertGreaterThanOrEqual(self::IPP_START, $number);

            $mass++;
        }

        $this->assertSame($report->patients() - count(self::ANCHORS), $mass);
    }

    /**
     * L'export rend une ligne par patient semé, IPP renseigné.
     *
     * C'est le contrat vu du consommateur : une ligne sans IPP ne permet aucun rapprochement, et
     * c'est le seul usage du fichier.
     *
     * @throws Throwable
     */
    public function testTheExportCarriesEveryPatientWithItsIpp(): void
    {
        $report = $this->report();

        self::$export_file = sys_get_temp_dir() . '/cohorte-' . uniqid('', true) . '.csv';

        $export = new CohortExport(self::class, self::NOW);
        $count  = $export->toFile(self::$export_file);

        $this->assertSame($report->patients(), $count);
        $this->assertSame(0, $export->withoutIpp(), 'Aucune ligne ne doit sortir sans IPP');

        // TOUS LES PATIENTS N'ONT PAS DE SÉJOUR, et c'est normal : le nombre de séjours se déduit de
        // la capacité et de la loi de Little, pas du nombre de patients. Ceux qui n'en obtiennent
        // aucun sont des dossiers d'identité seule — un hôpital réel en a aussi. Ce qui est vérifié
        // ici n'est donc pas qu'il n'y en a pas, mais que le COMPTEUR DIT LA VÉRITÉ : un export qui
        // annoncerait zéro manque alors qu'il en a laisserait le consommateur fabriquer du bruit
        // clinique autour de patients jamais admis.
        $this->assertSame(
            $this->patientsWithoutStay(),
            $export->withoutSejour(),
            'Le compteur de patients sans séjour doit correspondre à la base'
        );

        $rows = $this->readCsv(self::$export_file);

        $this->assertSame(CohortExport::COLUMNS, array_keys($rows[0]));
        $this->assertCount($report->patients(), $rows);

        // Les ancrés sont dans l'export, avec leur identité imposée : c'est par cette ligne qu'un
        // autre système les retrouvera.
        $by_ipp = [];
        foreach ($rows as $row) {
            $by_ipp[$row['ipp']] = $row;
        }

        // Même normalisation que testImposedIdentitiesReachTheSocleUnaltered : l'export rend ce que
        // la base contient, donc l'identité normalisée par l'identito-vigilance, et non la casse du
        // fichier d'ancres. C'est ce que le consommateur verra.
        foreach (self::ANCHORS as [$ipp, $nom, $prenom, $naissance, $sexe]) {
            $this->assertArrayHasKey($ipp, $by_ipp, "{$ipp} doit figurer dans l'export");
            $this->assertSame(
                CPatient::applyModeIdentitoVigilance(mb_strtoupper($nom)),
                $by_ipp[$ipp]['nom']
            );
            $this->assertSame(
                CPatient::applyModeIdentitoVigilance($prenom, true),
                $by_ipp[$ipp]['prenom']
            );
            $this->assertSame($naissance, $by_ipp[$ipp]['naissance']);
            $this->assertSame($sexe, $by_ipp[$ipp]['sexe']);
            $this->assertNotSame('', $by_ipp[$ipp]['date_entree'], "{$ipp} doit porter une admission");
        }

        // Les deux homonymes sortent sur DEUX lignes : c'est tout l'intérêt de l'IPP.
        $this->assertArrayHasKey('TST-0000161', $by_ipp);
        $this->assertArrayHasKey('TST-0000168', $by_ipp);
        $this->assertSame($by_ipp['TST-0000161']['nom'], $by_ipp['TST-0000168']['nom']);
    }

    /**
     * L'EXPORT REND LE PREMIER SÉJOUR, pas le plus récent.
     *
     * C'est la propriété dont dépend un système extérieur qui a codé en dur l'épisode prescrit par le
     * scénario. Un ancré vit dans l'hôpital comme les autres et peut obtenir d'autres séjours ; si
     * l'export rendait le dernier, il annoncerait un motif que le scénario n'a pas écrit, et deux
     * applications se contrediraient sur le même IPP.
     *
     * L'assertion porte sur le motif et le service IMPOSÉS : ils ne sont posés que sur le premier
     * séjour, donc les retrouver dans l'export prouve que c'est bien celui-là qui est rapporté.
     *
     * @throws Throwable
     */
    public function testTheExportReportsTheFirstStayAndNotTheLatest(): void
    {
        $this->report();

        $path = sys_get_temp_dir() . '/cohorte-premier-' . uniqid('', true) . '.csv';

        try {
            (new CohortExport(self::class, self::NOW))->toFile($path);

            $by_ipp = [];
            foreach ($this->readCsv($path) as $row) {
                $by_ipp[$row['ipp']] = $row;
            }

            foreach (self::ANCHORS as [$ipp, , , , , $service, $motif]) {
                if ($service === '') {
                    continue;
                }

                $first = $this->firstStayOf($this->patientByIpp($ipp));

                $this->assertSame(
                    $motif,
                    $by_ipp[$ipp]['motif_sejour'],
                    "Le motif exporté de {$ipp} doit être celui de son PREMIER séjour"
                );
                $unit = new CService();
                $unit->load($first->service_id);

                $this->assertSame(
                    $unit->nom,
                    $by_ipp[$ipp]['service'],
                    "Le service exporté de {$ipp} doit être celui de son PREMIER séjour"
                );
                $this->assertSame(
                    substr((string)($first->entree_reelle ?: $first->entree_prevue), 0, 19),
                    $by_ipp[$ipp]['date_entree'],
                    "La date exportée de {$ipp} doit être celle de son PREMIER séjour"
                );
            }

            // Les colonnes « actuel » disent où le patient est MAINTENANT, et sont vides s'il est
            // sorti. Le compte doit correspondre aux séjours en cours à l'instant de référence.
            $current = 0;
            foreach ($by_ipp as $row) {
                if ($row['service_actuel'] !== '') {
                    $current++;
                }
            }

            $this->assertSame($this->patientsCurrentlyAdmitted(), $current);
            $this->assertGreaterThan(0, $current, 'Le jeu de données enjambe « maintenant »');
        } finally {
            @unlink($path);
        }
    }

    /**
     * Patients semés dont un séjour est en cours à l'instant de référence.
     *
     * @throws Throwable
     */
    private function patientsCurrentlyAdmitted(): int
    {
        $ds = CSQLDataSource::get('std');

        $query = $ds->prepare(
            'SELECT COUNT(DISTINCT `patients`.`patient_id`) FROM `fixtures_reference` AS fr'
            . ' INNER JOIN `patients` ON `patients`.`patient_id` = `fr`.`object_id`'
            . ' INNER JOIN `sejour` ON `sejour`.`patient_id` = `patients`.`patient_id`'
            . " WHERE `fr`.`fixtures_class` = %1 AND `fr`.`object_class` = 'CPatient'"
            . ' AND COALESCE(`sejour`.`entree_reelle`, `sejour`.`entree_prevue`) <= %2'
            . ' AND COALESCE(`sejour`.`sortie_reelle`, `sejour`.`sortie_prevue`) >= %2',
            self::class,
            self::NOW
        );

        return (int)$ds->loadResult($query);
    }

    /**
     * Patients semés qui n'ont aucun séjour, comptés en base.
     *
     * @throws Throwable
     */
    private function patientsWithoutStay(): int
    {
        $ds = CSQLDataSource::get('std');

        $query = $ds->prepare(
            'SELECT COUNT(*) FROM `fixtures_reference` AS fr'
            . ' INNER JOIN `patients` ON `patients`.`patient_id` = `fr`.`object_id`'
            . ' LEFT JOIN `sejour` ON `sejour`.`patient_id` = `patients`.`patient_id`'
            . " WHERE `fr`.`fixtures_class` = %1 AND `fr`.`object_class` = 'CPatient'"
            . ' AND `sejour`.`sejour_id` IS NULL',
            self::class
        );

        return (int)$ds->loadResult($query);
    }

    /**
     * @throws Exception
     */
    private function patientByIpp(string $ipp): CPatient
    {
        $tag = CPatient::getTagIPP($this->report()->referential()->group()->_id);

        $idex        = new CIdSante400();
        $idex->tag   = $tag;
        $idex->id400 = $ipp;

        $idex->loadMatchingObject();

        $this->assertNotEmpty($idex->_id, "Aucun identifiant '{$ipp}' avec le tag '{$tag}'");

        $patient = new CPatient();
        $patient->load($idex->object_id);

        $this->assertNotEmpty($patient->_id, "Le patient de l'IPP '{$ipp}' est introuvable");

        return $patient;
    }

    /**
     * @throws Exception
     */
    private function firstStayOf(CPatient $patient): CSejour
    {
        $sejour             = new CSejour();
        $sejour->patient_id = $patient->_id;

        $sejours = $sejour->loadMatchingList('sejour_id ASC');

        $this->assertNotEmpty($sejours, 'Le patient doit avoir un séjour');

        return reset($sejours);
    }

    /**
     * Le slug de l'unité, retrouvé par son nom d'affichage dans le catalogue du décor.
     *
     * Passer par le catalogue plutôt que par une table de correspondance recopiée garde ce test
     * valable si les libellés changent.
     *
     * @throws Exception
     */
    private function slugOfService(int $service_id): string
    {
        $referential = $this->report()->referential();

        foreach ($referential->serviceSlugs() as $slug) {
            if ((int)$referential->service($slug)->_id === $service_id) {
                return $slug;
            }
        }

        return '';
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        $this->assertNotFalse($handle);

        $header = fgetcsv($handle);
        $rows   = [];

        while (($line = fgetcsv($handle)) !== false) {
            if ($line === [null]) {
                continue;
            }

            $rows[] = array_combine($header, $line);
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @throws Exception
     */
    private static function profile(): SeedProfile
    {
        return SeedProfile::named('small', self::SCALE);
    }

    /**
     * @throws Throwable
     */
    private function report(): SeedReport
    {
        if (self::$failure !== null) {
            throw self::$failure;
        }

        if (self::$report === null) {
            $writer = new OrmWriter(self::class);

            self::$registry = new IppRegistry($writer, self::IPP_PREFIX, self::IPP_START);

            $context = new SeedContext(
                self::profile(),
                new SeedRandom(self::SEED),
                $writer,
                self::NOW,
                self::$registry,
                AnchoredCohort::fromCsv(self::anchorsFile())
            );

            try {
                self::$report = (new HospiSeeder($context))->run();
            } catch (Throwable $e) {
                self::$failure = $e;

                throw $e;
            }
        }

        return self::$report;
    }

    /**
     * Écrit le fichier d'ancres, pour que le chemin LU soit celui de production.
     *
     * Le test pourrait construire une `AnchoredCohort` en mémoire ; passer par un vrai fichier
     * exerce aussi sa lecture et sa validation, c'est-à-dire ce qu'un opérateur rencontrera.
     */
    private static function anchorsFile(): string
    {
        if (self::$anchors_file === null) {
            self::$anchors_file = sys_get_temp_dir() . '/ancres-' . uniqid('', true) . '.csv';

            $lines = ['ipp,nom,prenom,naissance,sexe,service,motif_sejour'];
            foreach (self::ANCHORS as $anchor) {
                $lines[] = implode(',', $anchor);
            }

            file_put_contents(self::$anchors_file, implode("\n", $lines) . "\n");
        }

        return self::$anchors_file;
    }

    /**
     * Défait le jeu de données par le MÊME chemin que « ox-seed:hospi --purge ».
     *
     * @throws Throwable
     */
    private static function purgeSeed(): void
    {
        $purger = new SeedPurger(self::class);
        $purger->purge();
        $purger->assertClean();
    }
}
