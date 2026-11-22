<?php
/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */


namespace Ox\Core\Tests\Unit;

use Hospilibre\System\CFixturesReference;
use Hospilibre\Admin\CUser;
use Hospilibre\Admin\Tests\Fixtures\UsersFixtures;
use Ox\Tests\UnitTestHospilibre;


class CFixturesReferenceTest extends UnitTestHospilibre
{
    public function testLoadTarget()
    {
        $user_sample = $this->getObjectFromFixturesReference(CUser::class, UsersFixtures::REF_USER_LOREM_IPSUM);

        $fr               = new CFixturesReference();
        $fr->object_class = CUser::class;
        $fr->object_id    = $user_sample->_id;

        $target = $fr->loadTarget();

        // Avoid counting differences between _can, _canRead, ...
        $this->assertEquals($target->getPlainFields(), $user_sample->getPlainFields());
    }
}
