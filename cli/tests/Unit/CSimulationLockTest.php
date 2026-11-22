<?php

/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Cli\Tests\Unit;

use Ox\Cli\Console\Simulation\CSimulationLock;
use Ox\Tests\UnitTestHospilibre;

class CSimulationLockTest extends UnitTestHospilibre
{
    public function testSecondPassageRefuse(): void
    {
        $path = sys_get_temp_dir() . '/sim-lock-test-' . getmypid();
        $a = new CSimulationLock($path);
        $b = new CSimulationLock($path); // autre descripteur : flock les met en conflit

        $this->assertTrue($a->acquire());
        $this->assertFalse($b->acquire());   // non bloquant : refus immédiat

        $a->release();
        $this->assertTrue($b->acquire());    // libéré -> repris
        $b->release();
        @unlink($path);
    }
}
