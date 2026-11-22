<?php
/**
 * @package Hospilibre\Context
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Context;

use Hospilibre\System\AbstractConfigurationRegister;
use Hospilibre\System\CConfiguration;

/**
 * @codeCoverageIgnore
 */
class CConfigurationContext extends AbstractConfigurationRegister
{

    /**
     * @return mixed
     */
    public function register()
    {
        $configs = [
            "CGroups" => [
                "context" => [
                    "General" => [
                        "token_lifetime" => "enum list||10|15|20|25|30|45|60|120|180|240|300 localize",
                    ],
                ],
            ],
        ];


        CConfiguration::register($configs);
    }
}
