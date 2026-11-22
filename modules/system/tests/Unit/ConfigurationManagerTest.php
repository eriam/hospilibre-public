<?php
/**
 * @package Hospilibre\System
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\System\Tests;

use Hospilibre\System\ConfigurationManager;
use Ox\Tests\UnitTestHospilibre;

class ConfigurationManagerTest extends UnitTestHospilibre {
  public function testGetInstance() {
    $manager = ConfigurationManager::get();
    $this->assertInstanceOf(ConfigurationManager::class, $manager);

    $same_manager = ConfigurationManager::get();
    $this->assertSame($manager, $same_manager);
  }
}
