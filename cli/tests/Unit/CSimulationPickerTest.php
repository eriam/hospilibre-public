<?php

/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Cli\Tests\Unit;

use Ox\Cli\Console\Simulation\CSimulationPicker;
use Ox\Tests\UnitTestHospilibre;

class CSimulationPickerTest extends UnitTestHospilibre
{
    public function testTiragePondere(): void
    {
        $w = [10 => 1.0, 20 => 3.0]; // total 4 : [0, 0.25[ -> 10 ; [0.25, 1[ -> 20
        $this->assertSame(10, CSimulationPicker::weighted($w, 0.0));
        $this->assertSame(10, CSimulationPicker::weighted($w, 0.249));
        $this->assertSame(20, CSimulationPicker::weighted($w, 0.25));
        $this->assertSame(20, CSimulationPicker::weighted($w, 0.999));
    }

    public function testPoidsNulsIgnores(): void
    {
        $this->assertSame(20, CSimulationPicker::weighted([10 => 0.0, 20 => 2.0], 0.0));
        $this->assertNull(CSimulationPicker::weighted([10 => 0.0], 0.5));
        $this->assertNull(CSimulationPicker::weighted([], 0.5));
    }
}
