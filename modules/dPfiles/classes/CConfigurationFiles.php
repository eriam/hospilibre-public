<?php
/**
 * @package Hospilibre\Files
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Files;

use Ox\Core\Handlers\HandlerParameterBag;
use Hospilibre\System\AbstractConfigurationRegister;
use Hospilibre\System\CConfiguration;

/**
 * @codeCoverageIgnore
 */
class CConfigurationFiles extends AbstractConfigurationRegister
{
    /**
     * @inheritDoc
     */
    public function register()
    {
        CConfiguration::register(
            [
                "CGroups" => [
                    "dPfiles" => [
                        "General"         => [
                            "upload_max_filesize" => "str default|2M",
                            "extensions"          => "str",
                        ],
                        "CFile"           => [
                            "merge_to_pdf" => "bool default|0",
                        ],
                        "CFilesCategory"  => [
                            "show_empty" => "bool default|1",
                        ],
                        "CDocumentSender" => [
                            "system_sender" => "enum list||CEcDocumentSender localize",
                            "auto_max_load" => "num min|0 default|50",
                            "auto_max_send" => "num min|0 default|10",
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * @inheritDoc
     */
    public function getObjectHandlers(HandlerParameterBag $parameter_bag): void
    {
        $parameter_bag
            ->register(CFileTraceabilityHandler::class, false);
    }
}
