<?php

/**
 * @package Hospilibre\Astreintes
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Astreintes;

use Hospilibre\System\AbstractConfigurationRegister;
use Hospilibre\System\CConfiguration;

/**
 * Class CConfigurationAstreinte
 */
class CConfigurationAstreinte extends AbstractConfigurationRegister
{

    public function register(): void
    {
        CConfiguration::register(
            [
                "CGroups" => [
                    "astreintes" => [
                        "General" => [
                            "astreinte_admin_color"             => "color default|4f71c8",
                            "astreinte_informatique_color"      => "color default|c9be48",
                            "astreinte_medical_color"           => "color default|ec8b8b",
                            "astreinte_personnelsoignant_color" => "color default|59d95f",
                            "astreinte_technique_color"         => "color default|b0a5a5",
                        ],
                    ],
                ],
            ]
        );
    }
}
