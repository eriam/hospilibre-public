<?php

/**
 * @package Hospilibre\Files
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Files\Tests\Unit;

use Ox\Core\CMbArray;
use Ox\Core\CStoredObject;
use Hospilibre\Admin\CPermObject;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Files\CFile;
use Hospilibre\Files\CFilesCategory;
use Hospilibre\Files\CFilesCategoryDispatcher;
use Hospilibre\Populate\Generators\CFileGenerator;
use Hospilibre\Mediusers\CMediusers;
use Ox\Tests\UnitTestHospilibre;

/**
 * @group schedules
 */
class CFilesCategoryDispatcherTest extends UnitTestHospilibre
{
    public function testGetStatsNoData(): void
    {
        $cat = new CFilesCategory();

        $mock = $this->getMockBuilder(CFilesCategoryDispatcher::class)
            ->setConstructorArgs([$cat])
            ->onlyMethods(['getDistinctGroupsUsers'])
            ->getMock();
        $mock->method('getDistinctGroupsUsers')->willReturn([]);

        $this->assertEquals([], $mock->getStats());
    }
}
