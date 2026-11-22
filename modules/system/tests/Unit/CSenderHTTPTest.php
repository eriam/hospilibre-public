<?php

/**
 * @package Hospilibre\System
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\System\Tests\Unit;

use Ox\Core\CAppUI;
use Hospilibre\Admin\CUser;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\System\CSenderHTTP;
use Ox\Tests\UnitTestHospilibre;

/**
 * Description
 */
class CSenderHTTPTest extends UnitTestHospilibre
{
    public function testLoadFromUserNotFound(): void
    {
        $this->assertEquals(null, CSenderHTTP::loadFromUser(CUser::get()));
        $this->assertEquals(null, CSenderHTTP::loadFromUser(new CUser()));
    }

    public function testLoadFromUserFound(): void
    {
        $sender_http = new CSenderHTTP();
        $sender_http->nom      = 'CSenderHTTP-'. rand(1,100);
        $sender_http->actif    = 1;
        $sender_http->role     = CAppUI::conf("instance_role");
        $sender_http->user_id  = CUser::get()->_id;
        $sender_http->group_id =  CGroups::loadCurrent()->_id;
        $sender_http->store();

        $this->assertEquals($sender_http->_id, CSenderHTTP::loadFromUser(CUser::get())->_id);

        $sender_http->delete();
    }
}
