<?php

/**
 * @package Hospilibre\Cabinet
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Cabinet\Controllers\Legacy;

use Exception;
use Ox\Core\CAppUI;
use Ox\Core\CLegacyController;
use Ox\Core\CMbDT;
use Ox\Core\CStoredObject;
use Ox\Core\CView;
use Hospilibre\Cabinet\CConsultation;
use Hospilibre\Cabinet\CPlageconsult;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Mediusers\CFunctions;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Patients\CPatient;
use Hospilibre\PlanningOp\CSejour;
use Hospilibre\System\CPreferences;

class ConsultationLegacyController extends CLegacyController
{
    /**
     * @throws Exception
     */
    public function getConsultation(): void
    {
        $this->checkPermRead();

        $consult_id = CView::get('consult_id', 'num');

        CView::checkin();

        $consultation = CConsultation::findOrNew($consult_id);

        $this->renderJson($consultation);
    }

    public function weeklyPlanning(): void
    {
        $this->checkPermRead();

        if (!CAppUI::pref('new_semainier')) {
            CAppUI::redirect('m=cabinet&tab=vw_planning');
        }

        $user_id = CView::get('chirSel', 'ref class|CMediusers', true);
        $function_id = CView::get('function_id', 'ref class|CFunctions', true);
        $debut = CView::get('debut', 'date default|' . CMbDT::date(), true);

        CView::checkin();

        $group = CGroups::loadCurrent();

        $user = CMediusers::get();
        /* Select the connecter user if it's a practitioner and no user is selected */
        if ($user->isProfessionnelDeSante() && !$user_id) {
            $user_id = $user->_id;
        } else {
            $user = CMediusers::findOrNew($user_id);
        }

        $listChir = [];
        $function = CFunctions::findOrNew($function_id);
        if ($function_id) {
            $listChir = CConsultation::loadPraticiens(PERM_EDIT, $function_id, null, true);
        }

        // get desistements
        $count_si_desistement = 0;
        if (count($listChir) || $user_id) {
            $count_si_desistement = CConsultation::countDesistementsForDay(
                count($listChir) ? array_keys($listChir) : [$user_id],
                CMbDT::date()
            );
        }

        $debut = CMbDT::date('last sunday', $debut);

        $this->renderSmarty('vw_planning_new.tpl', [
            'listChirs'            => $listChir,
            'today'                => CMbDT::date(),
            'debut'                => CMbDT::date('+1 day', $debut),
            'fin'                  => CMbDT::date('next sunday', $debut),
            'prev'                 => CMbDT::date('-1 week', $debut),
            'next'                 => CMbDT::date('+1 week', $debut),
            'chirSel'              => $user_id,
            'user'                 => $user,
            'function'             => $function,
            'canEditPlage'         => (new CPlageconsult())->getPerm(PERM_EDIT),
            'count_si_desistement' => $count_si_desistement,
        ]);
    }

    public function refreshListConsultationsSejour()
    {
        $this->checkPermRead();
        $sejour_id = CView::get("sejour_id", "ref class|CSejour", true);
        CView::checkin();

        /** @var CSejour $sejour */
        $sejour = CSejour::findOrNew($sejour_id);
        if (isset($sejour->_id)) {
            $sejour->getPerm(PERM_READ);
            $sejour->loadRefsFwd();
            $sejour->loadRefsConsultations();

            CStoredObject::massLoadBackRefs($sejour->_ref_consultations, "context_ref_brancardages");

            /** @var CConsultation $_consultation */
            foreach ($sejour->_ref_consultations as $_consultation) {
                $_consultation->loadRefPraticien();
                $_consultation->loadRefsBrancardages();
            }
        }

        $params = [];
        $params["sejour"] = $sejour;
        $this->renderSmarty("inc_infos_consultation_sejour", $params);
    }

    public function addConsultationSuiviPatient(): void
    {

        $this->checkPermRead();
        $patient_id    = CView::get("patient_id", "ref class|CPatient");
        $callback      = CView::get("callback", "str");
        CView::checkin();

        $patient = new CPatient();
        $patient->load($patient_id);

        $consult = new CConsultation();
        $consult->_datetime = CMbDT::dateTime();
        $consult->type_consultation = "suivi_patient";
        $consult->patient_id = $patient->_id;

        $praticiens = CConsultation::loadPraticiens(PERM_EDIT);

        $this->renderSmarty(
            "inc_consult_suivi_patient.tpl",
            [
                "patient"    => $patient,
                "consult"    => $consult,
                "praticiens" => $praticiens,
                "callback"   => $callback,

            ]
        );
    }
}
