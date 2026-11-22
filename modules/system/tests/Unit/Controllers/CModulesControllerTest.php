<?php

/**
 * @package Hospilibre\System
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\System\Tests\Unit\Controllers;

use Ox\Core\Api\Resources\Collection;
use Ox\Core\Api\Resources\Item;
use Ox\Core\CMbString;
use Ox\Core\Kernel\Exception\HttpException;
use Ox\Core\Module\CModule;
use Hospilibre\System\Controllers\CModulesController;
use Ox\Tests\UnitTestHospilibre;
use Symfony\Component\HttpFoundation\Response;

class CModulesControllerTest extends UnitTestHospilibre
{
    public function testGetModuleList(): void
    {
        $controller = new CModulesController();
        foreach ([CModulesController::STATE_INSTALLED,CModulesController::STATE_ACTIVE,CModulesController::STATE_VISIBLE] as $state) {
            $modules = $this->invokePrivateMethod($controller, 'getModuleList', $state);
            foreach ($modules as $module) {
                $function = 'get' . CMbString::capitalize($state);
                $this->assertNotNull(CModule::$function($module->mod_name));
            }
        }
    }

    public function testGetModuleListInvalidState(): void
    {
        $controller = new CModulesController();

        $this->expectExceptionObject(
            new HttpException(
                Response::HTTP_NOT_FOUND,
                "State 'toto' is not in " . implode(', ', CModulesController::AVAILABLE_STATES)
            )
        );
        $this->invokePrivateMethod($controller, 'getModuleList', 'toto');
    }

    private function resourceIsValidModule(Item $item, string $mod_name): void
    {
        $module = $item->getDatas();
        $this->assertEquals($mod_name, $module->mod_name);
        $this->assertEquals('?m=' . $mod_name, $module->_url);
        $this->assertEquals(
            [
                CModulesController::LINK_MODULE_URL => '?m=' . $mod_name,
                CModulesController::LINK_MODULE_TABS_URL => "/api/modules/{$mod_name}/tabs",
            ],
            $item->getLinks()
        );
    }
}
