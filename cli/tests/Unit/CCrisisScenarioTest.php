<?php

/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Cli\Tests\Unit;

use Ox\Cli\Console\Simulation\CCrisisScenario;
use Ox\Cli\Console\Simulation\CSimulationConfig;
use Ox\Tests\UnitTestHospilibre;

class CCrisisScenarioTest extends UnitTestHospilibre
{
    private function scenario(): CCrisisScenario
    {
        return CCrisisScenario::fromArray([
            'name'        => 'test',
            'multipliers' => [
                0  => ['arrivals_per_day' => 1.0],
                10 => ['arrivals_per_day' => 3.0, 'avg_sejour_days' => 2.0],
                20 => ['arrivals_per_day' => 1.0],
            ],
            'fenetres'    => [
                ['de' => 5, 'a' => 15, 'deprogrammer_operations' => 0.5],
                ['de' => 8, 'a' => 25, 'deprogrammer_operations' => 0.2, 'refus_admissions' => 0.1],
                ['de' => 12, 'a' => 30, 'renfort_lits' => ['service' => 'Médecine interne', 'nb' => 4]],
            ],
            'admission'   => ['type' => 'urg', 'services_cibles' => ['Médecine interne', 'Inexistant']],
        ]);
    }

    public function testInterpolation(): void
    {
        $s = $this->scenario();
        $this->assertSame([], $s->factorsAt(-1));                                   // avant le début
        $this->assertEqualsWithDelta(1.0, $s->factorsAt(0)['arrivals_per_day'], 1e-9);
        $this->assertEqualsWithDelta(2.0, $s->factorsAt(5)['arrivals_per_day'], 1e-9);   // mi-chemin 1 -> 3
        $this->assertEqualsWithDelta(3.0, $s->factorsAt(10)['arrivals_per_day'], 1e-9);  // au pic
        // clé absente d'un point-clé = 1 à ce point : avg_sejour_days 1 (j0) -> 2 (j10) -> 1 (j20)
        $this->assertEqualsWithDelta(1.5, $s->factorsAt(5)['avg_sejour_days'], 1e-9);
        $this->assertEqualsWithDelta(1.5, $s->factorsAt(15)['avg_sejour_days'], 1e-9);
        $this->assertSame([], $s->factorsAt(21));                                   // après : nominal
    }

    public function testEffetsDesFenetres(): void
    {
        $s = $this->scenario();
        $this->assertSame(0.0, $s->effectsAt(4)['deprogrammer_operations']);
        $this->assertSame(0.5, $s->effectsAt(9)['deprogrammer_operations']);  // max des fenêtres actives
        $this->assertSame(0.1, $s->effectsAt(9)['refus_admissions']);
        $this->assertSame(0.2, $s->effectsAt(20)['deprogrammer_operations']);
        $this->assertSame(0.0, $s->effectsAt(26)['refus_admissions']);
        $this->assertCount(1, $s->getCapacityWindows());
    }

    public function testDureeEtFin(): void
    {
        $s = $this->scenario();
        $this->assertSame(30, $s->getDuration());          // max(dernier point 20, fin de fenêtre 30)
        $this->assertTrue($s->isActiveAt(0));
        $this->assertTrue($s->isActiveAt(30));
        $this->assertFalse($s->isActiveAt(31));
        $this->assertFalse($s->isActiveAt(-1));
    }

    public function testAdmissionDeCriseCompatibleAvecLePlacementEnLit(): void
    {
        // CAffectation::check() refuse de placer un séjour `urg` hors service d'urgence/UHCD :
        // l'afflux se trace par la PROVENANCE (5 = passage aux urgences), pas par le type.
        foreach (['epidemie', 'plan_blanc'] as $name) {
            $admission = CCrisisScenario::fromName($name)->getAdmission();
            $this->assertSame('comp', $admission['type'], $name);
            $this->assertSame('5', $admission['provenance'], $name);
            $this->assertSame('8', $admission['mode_entree'], $name);
        }
        // Défauts : pas de provenance imposée.
        $defaut = CCrisisScenario::fromArray(['name' => 'x'])->getAdmission();
        $this->assertNull($defaut['provenance']);
        $this->assertNull($defaut['mode_entree']);
    }

    public function testServicesManquants(): void
    {
        $this->assertSame(['Inexistant'], $this->scenario()->missingServices(['Médecine interne', 'Cardiologie']));
    }

    public function testScenarioInconnu(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CCrisisScenario::fromName('epidmie'); // faute de frappe dans HOSPILIBRE_CRISIS
    }

    public function testNomInvalide(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CCrisisScenario::fromName('../config/chu');
    }

    /** Chaque scénario se charge contre chaque profil ; un service cible absent est SIGNALÉ, jamais fatal. */
    public function testScenariosCroisesAvecProfils(): void
    {
        foreach (CCrisisScenario::NAMES as $name) {
            $scenario = CCrisisScenario::fromName($name);
            $this->assertSame($name, $scenario->getName());
            $this->assertGreaterThan(0, $scenario->getDuration());
            foreach (['chu', 'clinique', 'ssr'] as $profil) {
                $noms    = array_column(CSimulationConfig::fromProfile($profil)->getServices(), 'nom');
                $missing = $scenario->missingServices($noms);
                $this->assertIsArray($missing);
                foreach (array_keys($scenario->factorsAt(1)) as $rateKey) {
                    $this->assertContains($rateKey, CSimulationConfig::RATE_KEYS, "$name : clé « $rateKey » inconnue");
                }
            }
        }
        // Le scénario prévu pour chaque hôpital n'a AUCUN service manquant.
        $pairs = ['epidemie' => 'chu', 'plan_blanc' => 'clinique', 'crise_interne' => 'ssr'];
        foreach ($pairs as $name => $profil) {
            $noms = array_column(CSimulationConfig::fromProfile($profil)->getServices(), 'nom');
            $this->assertSame([], CCrisisScenario::fromName($name)->missingServices($noms), "$name × $profil");
        }
    }
}
