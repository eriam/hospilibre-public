<?php
/**
 * @package Hospilibre\Labo
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Labo;

use Hospilibre\System\AbstractConfigurationRegister;
use Hospilibre\System\CConfiguration;

/**
 * @codeCoverageIgnore
 */
class CConfigurationLabo extends AbstractConfigurationRegister
{
    /**
     * @return mixed
     */
    public function register()
    {
        CConfiguration::register(
            [
                "CGroups" => [
                    "dPlabo" => [
                        "CCatalogueLabo" => [
                            "remote_name" => "str default|LABO",
                            "remote_url"  => "str default|http://localhost/hospilibre/modules/dPlabo/remote/catalogue.xml",
                        ],

                        "CPackExamensLabo" => [
                            "remote_url" => "str default|http://localhost/hospilibre/modules/dPlabo/remote/pack.xml",
                        ],
                    ],
                ],
            ]
        );
    }
}
