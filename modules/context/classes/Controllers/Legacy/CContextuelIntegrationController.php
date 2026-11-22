<?php

/**
 * @package Hospilibre\Context
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Context\Controllers\Legacy;

use Exception;
use Ox\Core\CAppUI;
use Ox\Core\CFlotrGraph;
use Ox\Core\CLegacyController;
use Ox\Core\CMbDT;
use Ox\Core\CView;
use Hospilibre\Context\CContextualIntegration;
use Hospilibre\Etablissement\CGroups;

class CContextuelIntegrationController extends CLegacyController
{
    public function ajax_edit_integration(): void
    {
        $this->checkPermRead();

        $integration_id = CView::get("integration_id", "ref class|CContextualIntegration");

        CView::checkIn();

        $integration = CContextualIntegration::findOrNew($integration_id);
        $integration->loadRefsLocations();

        $this->renderSmarty(
            "inc_edit_integration",
            [
                'integration' => $integration,
            ]
        );
    }

    /**
     * Autocomplete des icÏ„nes FontAwesome
     * Cf. ContextualIntegration.iconAutocomplete
     *
     * @throw Exception
     */
    public function icon_autocomplete(): void
    {
        $this->checkPermRead();

        $keywords = CView::request("keywords", "str");
        $list_icon = CContextualIntegration::iconList();

        CView::checkIn();

        if ($keywords) {
            foreach ($list_icon as $_key => $_list) {
                if (strpos(strtolower($_key), strtolower($keywords)) === false) {
                    unset($list_icon[$_key]);
                }
            }
        }

        $this->renderSmarty(
            "inc_icon_autocomplete",
            [
                'list_icon' => $list_icon,
            ]
        );
    }
}
