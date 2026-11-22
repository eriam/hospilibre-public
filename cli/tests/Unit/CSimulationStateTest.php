<?php

/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Cli\Tests\Unit;

use Ox\Cli\Console\Simulation\CSimulationState;
use Ox\Tests\UnitTestHospilibre;

class CSimulationStateTest extends UnitTestHospilibre
{
    public function testCycleBootstrap(): void
    {
        $state = new CSimulationState();
        $state->ensureTable();
        $state->clear();

        $this->assertFalse($state->isBootstrapped());
        $this->assertNull($state->getGroupId());

        $state->markBootstrapped(4242, 'moyen');
        $this->assertTrue($state->isBootstrapped());
        $this->assertSame(4242, $state->getGroupId());

        $state->clear();
        $this->assertFalse($state->isBootstrapped());
    }

    public function testPhaseBootstrapping(): void
    {
        $state = new CSimulationState();
        $state->ensureTable();
        $state->clear();

        $this->assertNull($state->getPhase());

        // Semis commencé mais pas fini (conteneur tué) : group_id connu, PAS bootstrappé.
        $state->markBootstrapping(4242, 'chu');
        $this->assertSame('bootstrapping', $state->getPhase());
        $this->assertSame(4242, $state->getGroupId());   // simulate:reset peut donc purger
        $this->assertFalse($state->isBootstrapped());
        $this->assertSame('chu', $state->getConfigName());

        $state->markBootstrapped(4242, 'chu');
        $this->assertSame('seeded', $state->getPhase());
        $this->assertTrue($state->isBootstrapped());

        $state->clear();
    }

    public function testCrise(): void
    {
        $state = new CSimulationState();
        $state->ensureTable();
        $state->clear();
        $state->markBootstrapped(4242, 'chu');

        $this->assertNull($state->getCrisis());

        $state->setCrisis('epidemie', '2030-01-15 08:00:00');
        $this->assertSame(['name' => 'epidemie', 'start' => '2030-01-15 08:00:00'], $state->getCrisis());

        $state->clearCrisis();
        $this->assertNull($state->getCrisis());

        $state->clear();
    }

    public function testMigrationTableExistante(): void
    {
        // Une table créée par une version antérieure (sans phase ni crise) doit être complétée.
        $ds = \Ox\Core\CSQLDataSource::get('std');
        $ds->exec('DROP TABLE IF EXISTS `simulation_state`');
        $ds->exec('CREATE TABLE `simulation_state` (`id` TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,'
            . '`group_id` INT UNSIGNED NULL, `config_name` VARCHAR(64) NULL, `bootstrapped_at` DATETIME NULL,'
            . '`sim_clock` DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $ds->exec("INSERT INTO `simulation_state` VALUES (1, 77, 'moyen', '2030-01-01 00:00:00', '2030-01-02 00:00:00')");

        $state = new CSimulationState();
        $state->ensureTable();

        $this->assertSame('seeded', $state->getPhase());   // ligne ancienne = semis complet
        $this->assertTrue($state->isBootstrapped());
        $this->assertNull($state->getCrisis());

        $state->clear();
    }

    public function testHorloge(): void
    {
        $state = new CSimulationState();
        $state->ensureTable();
        $state->clear();

        // Aucun bootstrap : horloge et bootstrappedAt nuls.
        $this->assertNull($state->getClock());
        $this->assertNull($state->getBootstrappedAt());

        // markBootstrapped pose bootstrapped_at ET sim_clock (Plan 1).
        $state->markBootstrapped(4242, 'moyen');
        $this->assertNotNull($state->getClock());
        $this->assertNotNull($state->getBootstrappedAt());

        // setClock écrit une date lisible telle quelle.
        $state->setClock('2030-01-15 08:00:00');
        $this->assertSame('2030-01-15 08:00:00', $state->getClock());

        // clear() efface tout, horloge comprise.
        $state->clear();
        $this->assertNull($state->getClock());
    }
}
