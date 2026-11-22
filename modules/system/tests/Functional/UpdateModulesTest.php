<?php
/**
 * @package Hospilibre\Tests
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\System\Tests\Functional;

use Ox\Core\Chronometer;
use Hospilibre\System\Tests\Functional\Pages\CachePage;
use Hospilibre\System\Tests\Functional\Pages\SystemPage;
use Ox\Tests\SeleniumTestHospilibre;

/**
 * UpdateModulesTest
 *
 * @description Test the update of the module. First test launched to update hospilibre instances
 *
 * @screen SystemPage
 */
class UpdateModulesTest extends SeleniumTestHospilibre {

  /**
   * Mise Î° jour des modules et vérification qu'aucun module n'est Î° mettre Î° jour
   */
  public function testUpdateModule() {
    $this->markTestSkipped('need df maintenance, CCOnfigurationModelManager ');
    $chrono = new Chronometer();
    $chrono->start();
    $systemPage = new SystemPage($this);

    while (!$systemPage->isCoreModulesUpdated() && ($chrono->total < 300)) {
      $systemPage->doUpdate();
      $this->assertTrue($systemPage->isInfoMessage(), "Error during update");
      $chrono->step("update-core-mod");
    }
    $this->assertTrue($systemPage->isCoreModulesUpdated());
    $systemPage->displayInstalledModules();
    while (!$systemPage->isUserModulesUpdated() && ($chrono->total < 600)) {
      $systemPage->doAllUpdates();
      $chrono->step("update-user-mod");
    }

    $chrono->stop();
    $this->assertTrue($systemPage->isUserModulesUpdated());
  }
}