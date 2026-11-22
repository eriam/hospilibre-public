<?php
/**
 * @package Hospilibre\Admin\Tests
 * @author  SARL OpenXtrem <dev@openxtrem.com>
 * @license GNU General Public License, see http://www.gnu.org/licenses/gpl.html
 */

namespace Hospilibre\Admin\Rgpd\Tests\Unit;

use Hospilibre\Admin\Rgpd\CRGPDException;
use Hospilibre\Admin\Rgpd\CRGPDManager;
use Hospilibre\Etablissement\CGroups;
use Ox\Tests\TestsException;
use Ox\Tests\UnitTestHospilibre;

/**
 * Class CRGPDManagerTest
 */
class CRGPDManagerTest extends UnitTestHospilibre {

  /**
   *
   * @throws CRGPDException
   * @throws TestsException
   */
  public function testConstruct() {
    $group   = CGroups::loadCurrent();
    $manager = new CRGPDManager($group->_id);
    $this->assertInstanceOf(CRGPDManager::class, $manager);
  }

  /**
   *
   * @throws CRGPDException
   */
  public function testConstructFailed() {
    $this->expectException(CRGPDException::class);
    new CRGPDManager(null);
  }
}
