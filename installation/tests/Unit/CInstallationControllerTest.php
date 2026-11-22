<?php
/**
 * @package Core\Tests
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Core\Tests\Unit;

use Ox\Installation\Controllers\CInstallationController;
use Ox\Tests\UnitTestHospilibre;

/**
 * Class CInstallationControllerTest
 * @package Ox\Core\Tests\Unit
 */
class CInstallationControllerTest extends UnitTestHospilibre
{

    /**
     * @throws \Ox\Installation\CInstallationException
     */
    public function testConstruct()
    {
        $controller = new CInstallationController();
        $this->assertInstanceOf(CInstallationController::class, $controller);
    }
}
