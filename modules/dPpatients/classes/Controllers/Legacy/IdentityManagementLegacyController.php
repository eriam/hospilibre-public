<?php

/**
 * @package Hospilibre\Patients
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Patients\Controllers\Legacy;

use Exception;
use Ox\Core\CAppUI;
use Ox\Core\CLegacyController;
use Ox\Core\CMbException;
use Ox\Core\CMbString;
use Ox\Core\CView;
use Hospilibre\Patients\PatientIdentityService;

class IdentityManagementLegacyController extends CLegacyController
{
    /**
     * @throws Exception
     */
    public function listPatientState(): void
    {
        if (!CAppUI::pref("allowed_modify_identity_status")) {
            CAppUI::accessDenied();
        }
        $state = CMbString::upper(CView::get("state", "str notNull default|prov"));
        $page = (int)CView::get("page", "num notNull default|0");
        $date_min = CView::get("patient_state_date_min", "str", true);
        $date_max = CView::get("patient_state_date_max", "str", true);

        CView::checkin();

        $identity_service = new PatientIdentityService();
        try {
            [$patients, $patients_count] = $identity_service->listPatientsFromState(
                $state,
                $date_min,
                $date_max,
                $page
            );
        } catch (CMbException $e) {
            $e->stepAjax(UI_MSG_ERROR);
        }

        $this->renderSmarty(
            "patient_state/inc_list_patient_state",
            [
                "patients_count" => $patients_count,
                "count"          => $patients_count[$state],
                "patients"       => $patients,
                "state"          => $state,
                "page"           => $page,
            ]
        );
    }
}
