<?php
/**
 * @package Hospilibre\Tests
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\System\Tests\Functional;

use Hospilibre\System\Tests\Functional\Pages\CachePage;
use Ox\Tests\SeleniumTestHospilibre;

/**
 * CacheTest
 *
 * @description Tests to clear cache from the Hospilibre instance UI
 *
 * @screen SystemPage
 */
class CacheTest extends SeleniumTestHospilibre {

  /**
   * Test du vidage de cache de l'instance
   */
  public function testClearCache() {
    $cachePage = new CachePage($this);

    $cachePage->clearCache();

    $this->assertTrue(!empty($cachePage->getFeedBack()));
  }
}