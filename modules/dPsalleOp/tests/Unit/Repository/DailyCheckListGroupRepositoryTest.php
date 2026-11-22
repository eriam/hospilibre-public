<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Admin\Tests\Unit\Repository;

use Ox\Core\CMbArray;
use Ox\Core\CModelObjectException;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\SalleOp\CDailyCheckListGroup;
use Hospilibre\SalleOp\Repository\DailyCheckListGroupRepository;
use Ox\Tests\UnitTestHospilibre;

/**
 * Tests of CDailyCheckListGroup Repository object
 */
class DailyCheckListGroupRepositoryTest extends UnitTestHospilibre
{
    /**
     * Test to find all CDailyCheckListGroup object with CDailyCheckListType and their categories
     *
     * @return void
     * @throws CModelObjectException
     */
    public function testFindAllWithChecklistAndCategories(): void
    {
        $daily_check_list_group = $this->createDailyCheckListGroup();

        $daily_check_list_group_repository = new DailyCheckListGroupRepository();
        $check_list_groups = $daily_check_list_group_repository->findAllWithChecklistAndCategories(
            $daily_check_list_group
        );

        $check_list_group_ids = CMbArray::pluck($check_list_groups, "_id");

        $this->assertContains($daily_check_list_group->_id, $check_list_group_ids);
    }

    /**
     * Create a CDailyCheckListGroup object
     *
     * @return CDailyCheckListGroup
     * @throws CModelObjectException
     */
    public function createDailyCheckListGroup(): CDailyCheckListGroup
    {
        $group = CGroups::get();
        $daily_check_list_group = CDailyCheckListGroup::getSampleObject();
        $daily_check_list_group->group_id = $group->_id;
        $this->storeOrFailed($daily_check_list_group);

        return $daily_check_list_group;
    }
}
