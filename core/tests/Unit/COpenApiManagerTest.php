<?php
/**
 * @package Tests
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Core\Tests\Unit;

use Ox\Core\Kernel\Exception\RouteException;
use Ox\Core\Kernel\Routing\RouteManager;
use Ox\Core\OpenApi\COpenApiException;
use Ox\Core\OpenApi\COpenApiManager;
use Ox\Tests\UnitTestHospilibre;

/**
 * Class COpenApiManagerTest
 */
class COpenApiManagerTest extends UnitTestHospilibre
{

    /**
     * @throws COpenApiException
     * @throws RouteException
     * @group schedules
     */
    public function testBuild()
    {
        $route_manager = new RouteManager();
        $routes        = $route_manager->loadAllRoutes()->getRouteCollection();
        $manager       = new COpenApiManager();

        $msg = $manager->build($routes);
        $this->assertTrue($manager->documentationExists());
        $this->assertStringStartsWith('Generated openapi documentation file in', $msg);

        return $manager;
    }

    /**
     * @depends testBuild
     *
     * @param COpenApiManager $manager
     * @group schedules
     * @throws COpenApiException
     */
    public function testGetDocumentation(COpenApiManager $manager)
    {
        $this->assertIsArray($manager->getDocumentation());
    }

}
