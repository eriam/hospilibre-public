<?php

/**
 * @package Hospilibre\Soins
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\Soins\Controllers\Legacy;

use Ox\Core\CAppUI;
use Ox\Core\CLegacyController;
use Ox\Core\CMbArray;
use Ox\Core\CMbDT;
use Ox\Core\CStoredObject;
use Ox\Core\CView;
use Ox\Core\Module\CModule;
use Hospilibre\Admin\CAccessMedicalData;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Mpm\CPrescriptionLineMedicament;
use Hospilibre\Mpm\CPrescriptionLineMix;
use Hospilibre\Patients\CPatient;
use Hospilibre\PlanningOp\CSejour;
use Hospilibre\PlanSoins\CAdministration;
use Hospilibre\Prescription\CPrescription;
use Hospilibre\Prescription\PrescriptionService;

/**
 * Actions legacy pour le suivi de soins d'un sejour.
 *
 * @package Hospilibre\Soins\Controllers\Legacy
 */
class SuiviSoinsController extends CLegacyController
{
    /**
     * Affiche le dossier de suivi de soins d'un sejour.
     */
    public function ajaxViewDossierSuivi(): void
    {
        $this->checkPermRead();

        $sejourId = CView::get("sejour_id", "ref class|CSejour");
        $day      = CView::get("date", "date");
        CView::checkin();

        $currentGroup = CGroups::loadCurrent();

        $sejour = new CSejour();
        $sejour->load($sejourId);

        CAccessMedicalData::logAccess($sejour);

        $sejour->countTasks();
        $sejour->countRDVExternes();
        $sejour->countObjectifsSoins();
        $sejour->countObjectifsDelayWeek();
        $sejour->countAlertsNotHandled("medium", "observation");
        $sejour->isInPermission();

        $isPrescriptionActive = CModule::getActive("dPprescription");
        $isPlanSoinsActive    = $isPrescriptionActive && CPrescription::isPlanSoinsActive();
        $prescription         = $sejour->loadRefPrescriptionSejour();

        $peropCount = 0;
        if ($isPrescriptionActive && $isPlanSoinsActive) {
            $showPeropForGroup = CAppUI::conf(
                "dPprescription general show_perop_suivi_soins",
                $currentGroup->_guid
            );

            if ($showPeropForGroup && $prescription->_id) {
                $peropCount = CAdministration::countPerop($prescription->_id);
            }

            if (CAppUI::gconf("dPprescription general show_perop_suivi_soins")) {
                $lastOperation = $sejour->loadRefLastOperation(true);
                $lastOperation->loadRefsAnesthPerops();

                if ($lastOperation->_id && $lastOperation->_ref_anesth_perops) {
                    $peropCount += $lastOperation->_count_anesth_perops;
                }
            }
        }

        $this->renderSmarty(
            "inc_dossier_suivi",
            [
                "sejour"            => $sejour,
                "date"              => $day,
                "count_perop_adm"   => $peropCount,
                "plan_soins_active" => $isPlanSoinsActive,
                "prescription"      => $prescription,
            ]
        );
    }

    public function ajax_vw_commentaire_pharma()
    {
        $this->checkPermRead();

        $praticienId = CView::get("prat_id", "ref class|CMediusers");
        $day         = CView::get("date_com_pharma", "date default|" . CMbDT::date());
        $functionId  = CView::get("function_id", "ref class|CFunctions", true);

        CView::checkin();

        $service       = new PrescriptionService();
        $pharmaComLines = $service->getLinesWithPharmaComments($day, $praticienId, $functionId);

        $prescriptions = [];
        foreach ($pharmaComLines as $_line) {
            /* @var CPrescriptionLineMedicament|CPrescriptionLineMix $_line */
            $prescriptions[$_line->_ref_prescription->_guid] = $_line->_ref_prescription;
        }

        $today = CMbDT::date();
        foreach ($prescriptions as $_prescription) {
            /* @var CPrescription $_prescription */
            $_prescription->loadRefPatient();
            $_prescription->loadJourOp($today);

            $patient = $_prescription->_ref_patient;
            $sejour  = $_prescription->_ref_object;

            $patient->loadIPP();
            $patient->loadRefPhotoIdentite();
            $sejour->loadRefPraticien();
            $sejour->checkDaysRelative($today);
            $sejour->loadSurrAffectations('$date 00:00:00');
            $sejour->loadNDA();
            $sejour->loadRefCurrAffectation()->loadRefLit()->loadLastCleanup();
            $patient->loadRefDossierMedical();
            $dossierMedical = $patient->_ref_dossier_medical;

            if ($dossierMedical->_id) {
                $dossierMedical->loadRefsAllergies();
                $dossierMedical->loadRefsAntecedents();
                $dossierMedical->countAntecedents();
                $dossierMedical->countAllergies();
            }
        }

        $this->renderSmarty(
            "vw_reeval_antibio",
            [
                "prescriptions" => $prescriptions,
                "date"          => $day,
                "type"          => "com_pharma",
            ]
        );
    }

    public function ajax_vw_reeval_antibio()
    {
        $this->checkPermRead();

        $praticienId = CView::get("prat_id", "ref class|CMediusers");
        $reevalDate  = CView::get("date_reeval", "date default|" . CMbDT::date());
        $functionId  = CView::get("function_id", "ref class|CFunctions", true);

        CView::checkin();

        $service       = new PrescriptionService();
        $prescriptions = $service->getPrescriptionWithLinesAntibio(
            $reevalDate,
            $praticienId,
            $functionId
        );

        $today = CMbDT::date();
        foreach ($prescriptions as $_prescription) {
            /* @var CPrescription $_prescription */
            $_prescription->loadRefPatient();
            $_prescription->loadJourOp($today);

            $patient = $_prescription->_ref_patient;
            $sejour  = $_prescription->_ref_object;

            $patient->loadIPP();
            $patient->loadRefPhotoIdentite();
            $sejour->loadRefPraticien();
            $sejour->checkDaysRelative($today);
            $sejour->loadSurrAffectations('$date_reeval 00:00:00');
            $sejour->loadNDA();
            $patient->loadRefDossierMedical();
            $sejour->loadRefCurrAffectation()->loadRefLit()->loadLastCleanup();
            $dossierMedical = $patient->_ref_dossier_medical;

            if ($dossierMedical->_id) {
                $dossierMedical->loadRefsAllergies();
                $dossierMedical->loadRefsAntecedents();
                $dossierMedical->countAntecedents();
                $dossierMedical->countAllergies();
            }
        }

        $this->renderSmarty(
            "vw_reeval_antibio",
            [
                "prescriptions" => $prescriptions,
                "date"          => $reevalDate,
                "type"          => "reeval_antibio",
            ]
        );
    }

    public function httpreq_vw_bilan_list_prescriptions()
    {
        $this->checkPermRead();

        $currentUser = CMediusers::get();
        $praticienId = CView::get("prat_bilan_id", "ref class|CMediusers default|$currentUser->_id", true);
        // par default, date du jour
        $dateMin          = CView::get("_date_entree_prevue", "date default|now", true);
        $dateMax          = CView::get("_date_sortie_prevue", "date default|now", true);
        $board            = CView::get("board", "bool default|0");
        // par default les non signees
        $signee           = CView::get("signee", "enum list|0|all default|0", true);
        // sejour - sortie_manquante
        $typePrescription = CView::get(
            "type_prescription",
            "enum list|sejour|sortie_manquante default|sejour",
            true
        );
        $functionId       = CView::get("function_id", "ref class|CFunctions", true);

        CView::enableSlave();
        CView::checkin();

        $dateMin .= " 00:00:00";
        $dateMax .= " 23:59:59";

        $service       = new PrescriptionService();
        $prescriptions = $service->getPrescriptions(
            $dateMin,
            $dateMax,
            $typePrescription,
            $signee,
            $praticienId,
            $functionId
        );

        $sejours = [];
        foreach ($prescriptions as $_prescription) {
            $sejours[$_prescription->object_id] = $_prescription->_ref_object;
        }

        $patients = CStoredObject::massLoadFwdRef($sejours, "patient_id");
        CStoredObject::massLoadBackRefs($patients, "bmr_bhre");
        CSejour::massLoadNDA($sejours);
        CPatient::massLoadIPP($patients);
        CPatient::massCountPhotoIdentite($patients);

        /** @var CSejour $_sejour */
        foreach ($sejours as $_sejour) {
            $_sejour->loadRefPatient()->updateBMRBHReStatus();
        }

        if (count($prescriptions)) {
            CMbArray::pluckSort($prescriptions, SORT_ASC, "_ref_object", "_ref_patient", "nom");
        }

        $sejour            = new CSejour();
        $sejour->_date_min = $dateMin;
        $sejour->_date_max = $dateMax;

        $today = CMbDT::date();

        // Regroupement par type de praticien
        $prescriptionsByPraticienType = [];

        foreach ($prescriptions as $_prescription) {
            $_prescription->loadRefPatient();

            $patient = $_prescription->_ref_patient;
            $sejour  = $_prescription->_ref_object;

            $patient->loadRefPhotoIdentite();

            $praticien = $sejour->loadRefPraticien();
            $sejour->checkDaysRelative($today);
            $sejour->loadSurrAffectations($dateMin);

            $sejour->loadRefCurrAffectation()->loadRefLit()->loadLastCleanup();

            if ($_prescription->_id) {
                $_prescription->loadJourOp($today);
            }

            $patient->loadRefDossierMedical();
            $dossierMedical = $patient->_ref_dossier_medical;

            if ($dossierMedical->_id) {
                $dossierMedical->loadRefsAllergies();
                $dossierMedical->loadRefsAntecedents();
                $dossierMedical->countAntecedents();
                $dossierMedical->countAllergies();
            }

            $prescriptionsByPraticienType[$praticien->_user_type_view][$_prescription->_id] = $_prescription;
        }

        $counterPrescription = $prescriptions && count($prescriptions) ? count($prescriptions) : 0;

        $this->renderSmarty(
            "inc_vw_bilan_list_prescriptions",
            [
                "prescriptions_by_praticiens_type" => $prescriptionsByPraticienType,
                "board"                            => $board,
                "date"                             => $dateMin,
                "default_tab"                      => "prescription_sejour",
                "counter_prescription"             => $counterPrescription,
            ]
        );
    }

    public function httpreq_vw_bilan_list_inscriptions()
    {
        $this->checkPermRead();

        $currentUser = CMediusers::get();
        $praticienId = CView::get("prat_bilan_id", "ref class|CMediusers default|$currentUser->_id", true);
        // par default, date du jour
        $dateMin     = CView::get("_date_entree_prevue", "date default|now", true);
        $dateMax     = CView::get("_date_sortie_prevue", "date default|now", true);
        $functionId  = CView::get("function_id", "ref class|CFunctions", true);
        $board       = CView::get("board", "bool default|0");

        CView::checkin();
        CView::enableSlave();

        $dateMin .= " 00:00:00";
        $dateMax .= " 23:59:59";

        $service       = new PrescriptionService();
        $prescriptions = $service->getPrescriptionWithInscription($dateMin, $dateMax, $praticienId, $functionId);

        $sejours = CStoredObject::massLoadFwdRef($prescriptions, "object_id", "CSejour");
        CStoredObject::massLoadFwdRef($sejours, "praticien_id");
        CSejour::massLoadNDA($sejours);
        $patients = CStoredObject::massLoadFwdRef($sejours, "patient_id");
        CStoredObject::massLoadBackRefs($patients, "dossier_medical");
        CStoredObject::massLoadBackRefs($patients, "bmr_bhre");
        CPatient::massLoadIPP($patients);
        CPatient::massCountPhotoIdentite($patients);

        foreach ($prescriptions as $_presc) {
            $_presc->loadRefObject();
            $_presc->loadRefPatient();
        }

        if (count($prescriptions)) {
            CMbArray::pluckSort($prescriptions, SORT_ASC, "_ref_object", "_ref_patient", "nom");
        }

        $sejour            = new CSejour();
        $sejour->_date_min = $dateMin;
        $sejour->_date_max = $dateMax;

        $today = CMbDT::date();

        // Regroupement par type de praticien
        $prescriptionsByPraticienType = [];

        /* @var CPrescription $_prescription */
        foreach ($prescriptions as $_prescription) {
            $patient = $_prescription->_ref_patient;
            $sejour  = $_prescription->_ref_object;

            $patient->loadRefPhotoIdentite();
            $patient->updateBMRBHReStatus();

            $praticien = $sejour->loadRefPraticien();
            $sejour->checkDaysRelative($today);
            $sejour->loadSurrAffectations($dateMin);
            $sejour->loadRefCurrAffectation()->loadRefLit()->loadLastCleanup();

            if ($_prescription->_id) {
                $_prescription->loadJourOp($today);
            }

            $patient->loadRefDossierMedical();
            $dossierMedical = $patient->_ref_dossier_medical;

            if ($dossierMedical->_id) {
                $dossierMedical->loadRefsAllergies();
                $dossierMedical->loadRefsAntecedents();
                $dossierMedical->countAntecedents();
                $dossierMedical->countAllergies();
            }

            $prescriptionsByPraticienType[$praticien->_user_type_view][$_prescription->_id] = $_prescription;
        }

        $counterPrescription = $prescriptions && count($prescriptions) ? count($prescriptions) : 0;

        $this->renderSmarty(
            "inc_vw_bilan_list_prescriptions",
            [
                "prescriptions_by_praticiens_type" => $prescriptionsByPraticienType,
                "board"                            => $board,
                "date"                             => $dateMin,
                "default_tab"                      => "prescription_sejour",
                "default_id"                       => "inscriptions",
                "counter_prescription"             => $counterPrescription,
            ]
        );
    }
}
