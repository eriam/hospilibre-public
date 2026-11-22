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
use Hospilibre\PlanningOp\COperation;
use Hospilibre\PlanningOp\CSejour;
use Ox\Cli\Seed\Decor\SurgicalClinicDecor;
use Ox\Cli\Seed\HospiSeeder;
use Ox\Cli\Seed\OrmWriter;
use Ox\Cli\Seed\SeedContext;
use Ox\Cli\Seed\SeedProfile;
use Ox\Cli\Seed\SeedPurger;
use Ox\Cli\Seed\SeedRandom;
use Ox\Cli\Seed\SeedReport;
use Ox\Tests\UnitTestHospilibre;
use Throwable;

/**
 * UN SECOND DÉCOR PASSE-T-IL LE SOCLE ?
 *
 * Tout le reste de la suite vérifie le décor hospitalier. Ce test-ci vérifie qu'un AUTRE décor —
 * une clinique chirurgicale, sans service d'urgences, avec ses propres unités, ses propres actes et ses
 * propres proportions — produit un jeu de données que l'ORM accepte réellement. C'est la seule façon de
 * savoir si l'API de décors tient : un dry-run confirme l'échelle, pas que la base dira oui.
 *
 * Les règles du socle qu'un décor peut heurter sans le savoir sont nombreuses — un séjour urgent hors
 * unité d'accueil, un lit d'un autre service, un praticien sans fonction — et aucune ne se manifeste
 * avant l'écriture.
 *
 * Le jeu est construit UNE FOIS pour toute la classe, à une échelle volontairement réduite : ce qui est
 * vérifié ici est la recevabilité, pas le volume.
 */
class SeedDecorSocleTest extends UnitTestHospilibre
{
    private const NOW = '2026-06-15 09:00:00';

    private const SEED = 616;

    /** Échelle réduite mais cohérente : les lits et la fenêtre sont réduits ENSEMBLE. */
    private const SCALE = ['beds' => 30, 'window' => 1];

    /** @var SeedReport|null */
    private static $report;

    /** @var Exception|null Échec de construction, retenu et relancé tel quel */
    private static $failure;

    public static function tearDownAfterClass(): void
    {
        self::purgeSeed();
        self::$report  = null;
        self::$failure = null;

        parent::tearDownAfterClass();
    }

    /**
     * @throws Exception
     */
    public function testTheClinicIsWrittenAndLooksLikeAClinic(): void
    {
        $report = $this->report();

        $this->assertNotEmpty($report->referential()->group()->_id, "L'établissement doit être écrit");
        $this->assertStringStartsWith(
            'Clinique',
            (string)$report->referential()->group()->text,
            "Le nommage vient du décor : « Clinique … » et non « Centre Hospitalier … »"
        );
        $this->assertNotEmpty($report->referential()->group()->cp, 'La clinique est implantée quelque part');

        // Six unités, dont aucune d'accueil des urgences.
        $this->assertCount(6, $report->referential()->services());
        $this->assertSame([], $report->referential()->emergencySlugs(), "Une clinique n'a pas d'urgences");
        $this->assertSame(0, $report->emergencyBeds());
    }

    /**
     * Aucun séjour urgent, et surtout aucune affectation qui aurait dû être refusée.
     *
     * @throws Exception
     */
    public function testNoEmergencyStayIsProducedNorHosted(): void
    {
        $urgent = 0;

        foreach ($this->sejours() as $sejour) {
            if ($sejour->type === 'urg') {
                $urgent++;
            }
        }

        $this->assertSame(
            0,
            $urgent,
            "Le décor de clinique ne déclare aucun séjour urgent : le socle refuserait de l'héberger"
        );
    }

    /**
     * Les invariants structurels valent pour TOUT décor : ils appartiennent au moteur, pas au décor.
     *
     * @throws Exception
     */
    public function testTheStructuralInvariantsHoldForAnotherDecor(): void
    {
        $by_bed = [];

        foreach ($this->affectations() as $affectation) {
            $this->assertNotEmpty($affectation->service_id, "Affectation {$affectation->_id} sans service");

            if (!$affectation->lit_id) {
                continue;
            }

            // Invariant 1 : le lit appartient au service de l'affectation.
            $bed = $affectation->loadRefLit();
            $this->assertNotNull($bed->_id);

            $room = $bed->loadRefChambre();
            $this->assertEquals(
                $affectation->service_id,
                $room->service_id,
                "Le lit {$bed->_id} n'est pas du service de l'affectation {$affectation->_id}"
            );

            $by_bed[$affectation->lit_id][] = [$affectation->entree, $affectation->sortie];
        }

        // Invariant 2 : deux affectations ne se chevauchent jamais sur le même lit.
        foreach ($by_bed as $bed => $intervals) {
            usort(
                $intervals,
                static function (array $a, array $b): int {
                    return strcmp($a[0], $b[0]);
                }
            );

            for ($i = 1; $i < count($intervals); $i++) {
                $this->assertLessThanOrEqual(
                    $intervals[$i][0],
                    $intervals[$i - 1][1],
                    "Lit {$bed} : deux affectations se chevauchent"
                );
            }
        }

        $this->assertNotEmpty($by_bed, 'Des lits doivent être occupés');
    }

    /**
     * Le programme opératoire de la clinique est cohérent avec SES actes, qui ne sont pas ceux de
     * l'hôpital.
     *
     * @throws Exception
     */
    public function testTheProgrammeUsesTheClinicOwnProcedures(): void
    {
        $catalogue = (new SurgicalClinicDecor())->catalogue();
        $services  = [];

        foreach ($this->report()->referential()->services() as $slug => $entry) {
            $services[$entry['service']->_id] = $slug;
        }

        $seen = 0;

        foreach ($this->operations() as $operation) {
            $sejour = new CSejour();
            $sejour->load($operation->sejour_id);

            $slug = $services[$sejour->service_id] ?? null;
            $this->assertNotNull($slug, "Séjour {$sejour->_id} hors des unités du décor");

            $seen++;
            $this->assertContains(
                $operation->libelle,
                $catalogue->procedures($slug),
                "« {$operation->libelle} » n'est pas un acte de '{$slug}'"
            );
        }

        $this->assertGreaterThan(0, $seen, 'Une clinique chirurgicale doit opérer');
    }

    /**
     * @throws Exception
     */
    private function report(): SeedReport
    {
        if (self::$failure !== null) {
            throw self::$failure;
        }

        if (self::$report === null) {
            $context = new SeedContext(
                SeedProfile::named('small', self::SCALE, new SurgicalClinicDecor()),
                new SeedRandom(self::SEED),
                new OrmWriter(self::class),
                self::NOW
            );

            try {
                self::$report = (new HospiSeeder($context))->run();
            } catch (Exception $e) {
                self::$failure = $e;

                throw $e;
            }
        }

        return self::$report;
    }

    /**
     * @return CSejour[]
     * @throws Exception
     */
    private function sejours(): array
    {
        $sejour           = new CSejour();
        $sejour->group_id = $this->report()->referential()->group()->_id;

        return $sejour->loadMatchingList('sejour_id');
    }

    /**
     * @return CAffectation[]
     * @throws Exception
     */
    private function affectations(): array
    {
        $ids = [];
        foreach ($this->sejours() as $sejour) {
            $ids[] = $sejour->_id;
        }

        if ($ids === []) {
            return [];
        }

        $affectation = new CAffectation();

        return $affectation->loadList(['sejour_id' => $affectation->getDS()->prepareIn($ids)], 'affectation_id');
    }

    /**
     * @return COperation[]
     * @throws Exception
     */
    private function operations(): array
    {
        $ids = [];
        foreach ($this->sejours() as $sejour) {
            $ids[] = $sejour->_id;
        }

        if ($ids === []) {
            return [];
        }

        $operation = new COperation();

        return $operation->loadList(['sejour_id' => $operation->getDS()->prepareIn($ids)], 'operation_id');
    }

    /**
     * @throws Throwable
     */
    private static function purgeSeed(): void
    {
        $purger = new SeedPurger(self::class);
        $purger->purge();
        $purger->assertClean();
    }
}
