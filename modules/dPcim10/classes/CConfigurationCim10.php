<?php
/**
 * @package Hospilibre\Cim10
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Cim10;

use Hospilibre\System\AbstractConfigurationRegister;
use Hospilibre\System\CConfiguration;

/**
 * @codeCoverageIgnore
 */
class CConfigurationCim10 extends AbstractConfigurationRegister
{

    /**
     * @return mixed
     */
    public function register()
    {
        CConfiguration::register(
            [
                'CGroups' => [
                    'dPcim10' => [
                        'diagnostics' => [
                            'restrict_code_usage' => 'bool default|0',
                        ],
                    ],
                ],
            ]
        );
    }
}
