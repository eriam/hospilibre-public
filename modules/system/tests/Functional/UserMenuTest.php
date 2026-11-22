<?php
/**
 * @package Hospilibre\Tests
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\System\Tests\Functional;

use Hospilibre\Mediusers\Tests\Functional\Pages\MediusersPage;
use Ox\Tests\HomePage;
use Ox\Tests\SeleniumTestHospilibre;

/**
 * Test on the user menu actions
 *
 * @description Test the availables options on user menu
 *
 * @screen HomePage
 */
class UserMenuTest extends SeleniumTestHospilibre {

  /** @var HomePage $page */
  public $page;

  /**
   * @inheritdoc
   */
  public function setUpPage() {
    $this->markTestSkipped('Not tested on gitlab-ci');
    parent::setUpPage();
    $this->page = new HomePage($this);
  }

  /**
   * Teste le changement d'utilisateur Î° l'aide du menu utilisateur
   */
  public function testSwitchUserOk() {
    $this->markTestSkipped('Not tested on gitlab-ci');
    new MediusersPage($this);
    $this->importObject("mediusers/tests/Functional/data/mediuser.xml");
    $this->page->changeUser("BATMAN");
    sleep(3);
    $this->assertEquals("Bruce WAYNE", $this->byCss(".welcome")->text());
  }
}
