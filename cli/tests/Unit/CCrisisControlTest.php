<?php

/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Cli\Tests\Unit;

use Ox\Cli\Console\Simulation\CCrisisControl;
use Ox\Cli\Console\Simulation\CSimulationState;
use Ox\Tests\UnitTestHospilibre;

class CCrisisControlTest extends UnitTestHospilibre
{
    private function state(): CSimulationState
    {
        $state = new CSimulationState();
        $state->ensureTable();
        $state->clear();
        $state->markBootstrapped(4242, 'chu');

        return $state;
    }

    public function testStartPoseLaCrise(): void
    {
        $state = $this->state();
        CCrisisControl::start($state, 'epidemie', '2030-01-10 08:00:00');
        $this->assertSame(['name' => 'epidemie', 'start' => '2030-01-10 08:00:00'], $state->getCrisis());
        $state->clear();
    }

    public function testStartRefuseSiCriseActive(): void
    {
        // Redémarrage d'une instance en crise : la crise ne doit pas repartir à zéro.
        $state = $this->state();
        CCrisisControl::start($state, 'epidemie', '2030-01-10 08:00:00');
        try {
            CCrisisControl::start($state, 'epidemie', '2030-01-20 08:00:00'); // J10 : encore active
            $this->fail('Une crise active aurait dû être protégée');
        } catch (\RuntimeException $e) {
            $this->assertSame('2030-01-10 08:00:00', $state->getCrisis()['start']); // inchangée
        }
        $state->clear();
    }

    public function testStartRemplaceUneCriseTerminee(): void
    {
        $state = $this->state();
        CCrisisControl::start($state, 'plan_blanc', '2030-01-01 08:00:00'); // durée 14 j
        CCrisisControl::start($state, 'epidemie', '2030-03-01 08:00:00');
        $this->assertSame('epidemie', $state->getCrisis()['name']);
        $state->clear();
    }

    public function testStartScenarioInconnuNeTouchePasLEtat(): void
    {
        $state = $this->state();
        try {
            CCrisisControl::start($state, 'epidmie', '2030-01-10 08:00:00');
            $this->fail('Scénario inconnu accepté');
        } catch (\InvalidArgumentException $e) {
            $this->assertNull($state->getCrisis());
        }
        $state->clear();
    }

    public function testJourRelatif(): void
    {
        $this->assertSame(0, CCrisisControl::relativeDay('2030-01-10 08:00:00', '2030-01-10 23:00:00'));
        $this->assertSame(10, CCrisisControl::relativeDay('2030-01-10 08:00:00', '2030-01-20 01:00:00'));
    }
}
