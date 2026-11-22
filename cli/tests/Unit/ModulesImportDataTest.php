<?php

/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Cli\Tests\Unit;

use Ox\Cli\Console\ModulesImportData;
use Ox\Tests\UnitTestHospilibre;

class ModulesImportDataTest extends UnitTestHospilibre
{
    public function testModuleInactifNImportePas(): void
    {
        $this->assertFalse(ModulesImportData::needsImport(false, false, 0));
        $this->assertFalse(ModulesImportData::needsImport(false, true, 0));
    }

    public function testModuleActifTableAbsenteImporte(): void
    {
        $this->assertTrue(ModulesImportData::needsImport(true, false, 0));
    }

    public function testModuleActifTableVideImporte(): void
    {
        $this->assertTrue(ModulesImportData::needsImport(true, true, 0));
    }

    public function testModuleActifTablePeupleeSkip(): void
    {
        $this->assertFalse(ModulesImportData::needsImport(true, true, 1));
        $this->assertFalse(ModulesImportData::needsImport(true, true, 19154));
    }
}
