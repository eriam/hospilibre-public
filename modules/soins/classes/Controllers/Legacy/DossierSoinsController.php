<?php

/**
 * @package Hospilibre\Soins\Controllers\Legacy
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\Soins\Controllers\Legacy;

use Exception;
use Ox\Core\CAppUI;
use Ox\Core\CLegacyController;
use Ox\Core\CStoredObject;
use Ox\Core\CView;
use Ox\Core\Module\CModule;
use Ox\Interop\Imeds\CImeds;
use Hospilibre\Admin\CAccessMedicalData;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Mpm\CPrescriptionLineMedicament;
use Hospilibre\Mpm\CPrescriptionLineMix;
use Hospilibre\Mpm\CPrescriptionLineMixItem;
use Hospilibre\Patients\CDossierMedical;
use Hospilibre\Patients\CPatient;
use Hospilibre\PlanningOp\COperation;
use Hospilibre\PlanningOp\CSejour;
use Hospilibre\PlanSoins\CAdministration;
use Hospilibre\Soins\DossierSoinsService;
use Hospilibre\System\CExchangeSource;
use Hospilibre\System\CSourceHTTP;

/**
 * Contrôleur legacy du dossier de soins.
 *
 * @package Hospilibre\Soins\Controllers\Legacy
 */
class DossierSoinsController extends CLegacyController
{
    /**
     * Assemble la chronologie des gestes et administrations perop d'une intervention donnée.
     *
     * Dérivé de l'ancien script "ajax_vw_perop_administrations.php", spécialisé ici
     * pour une seule opération.
     */
    public function ajaxViewPeropOperation(): void
    {
        $this->checkPermRead();

        $prescriptionId      = CView::get("prescription_id", "ref class|CPrescription");
        $sejourId            = CView::get("sejour_id", "ref class|CSejour");
        $operationId         = CView::get("operation_id", "ref class|COperation");
        $showAdministrations = CView::get("show_administrations", "bool default|0");

        CView::checkin();

        $sejour        = CSejour::find($sejourId);
        $operation     = COperation::findOrFail($operationId);
        $operationList = $sejour->loadRefsOperations(["annulee" => "= '0'"]);

        CAccessMedicalData::logAccess($sejour);

        // Regroupement chronologique des gestes et administrations perop.
        $perops    = [];
        $eventCount = 0;

        // Administrations perop rattachées à la prescription et à l'opération.
        $administrations = CAdministration::getPerop($prescriptionId, true, $operation->_id);

        foreach ($administrations as $administration) {
            $administration->loadRefsFwd();
            $target = $administration->_ref_object;

            if ($target instanceof CPrescriptionLineMedicament || $target instanceof CPrescriptionLineMixItem) {
                $produit = $target->_ref_produit;
                $produit->loadRapportUnitePriseByCIS($target);
                $produit->updateRatioMassique();

                if ($produit->_ratio_mg) {
                    $administration->_quantite_mg = $administration->quantite / $produit->_ratio_mg;
                }

                [$uniteLivret, $qteLivret] = CPrescriptionLineMedicament::computeQteUnitLTPerop(
                    $target,
                    $administration->quantite
                );

                $administration->_ref_object->_unite_livret = $uniteLivret;
                $administration->_ref_object->_qte_livret   = $qteLivret;
            }

            $administration->_perop_section = CAdministration::getSectionPerop(
                $operation->_id,
                $administration->dateTime
            );

            $perops[$administration->dateTime][$administration->_guid] = $administration;
            $eventCount++;
        }

        if ($sejour->_ref_prescription_sejour && $sejour->_ref_prescription_sejour->_id) {
            // Perfusions perop : on matérialise leur pose et leur retrait.
            $mixFilter                  = new CPrescriptionLineMix();
            $mixFilter->prescription_id = $prescriptionId;
            $mixFilter->perop           = 1;
            /** @var CPrescriptionLineMix[] $mixes */
            $mixes = $mixFilter->loadMatchingList();

            CStoredObject::massLoadFwdRef($mixes, "praticien_id");

            foreach ($mixes as $mix) {
                $mix->loadRefPraticien();
                $mix->loadRefsLines();

                if ($mix->date_pose && $mix->time_pose) {
                    $poseSection          = CAdministration::getSectionPerop($operation->_id, $mix->_pose);
                    $mix->_perop_section  = $poseSection;

                    $perops[$poseSection][$mix->_pose][$mix->_guid] = $mix;
                }

                if ($mix->date_retrait && $mix->time_retrait) {
                    $retraitSection      = CAdministration::getSectionPerop($operation->_id, $mix->_retrait);
                    $mix->_perop_section = $retraitSection;

                    $perops[$retraitSection][$mix->_retrait][$mix->_guid] = $mix;
                }

                $eventCount++;
            }

            ksort($perops);
        }

        // Gestes d'anesthésie perop de l'intervention.
        $anesthPerops = $operation->loadRefsAnesthPerops();

        if (!$showAdministrations) {
            foreach ($anesthPerops as $anesthPerop) {
                $anesthPerop->updateFormFields();
                $anesthPerop->loadRefUser();

                $anesthPerop->_perop_section = CAdministration::getSectionPerop(
                    $operation->_id,
                    $anesthPerop->datetime
                );

                $perops[$anesthPerop->datetime][$anesthPerop->_guid] = $anesthPerop;
                $eventCount++;
            }
        }

        $this->renderSmarty(
            "inc_vw_perop_administrations",
            [
                "perops"                       => $perops,
                "operation"                    => $operation,
                "operations"                   => $operationList,
                "count_administrations_gestes" => $eventCount,
                "show_administrations"         => $showAdministrations,
                "prescription_id"              => $prescriptionId,
            ]
        );
    }

    public function reloadPatientBanner()
    {
        $this->checkPermRead();

        $patientId = CView::get("patient_id", "ref class|CPatient");

        CView::checkin();

        $patient = CPatient::findOrFail($patientId);
        $patient->loadRefDossierMedical();
        $patient->loadRefLatestConstantes();

        $this->renderSmarty(
            "inc_infos_patients_soins",
            [
                "patient" => $patient,
            ]
        );
    }

    /**
     * Charge et affiche le dossier de séjour (remplaçant du script ajax_vw_dossier_sejour).
     *
     * @throws Exception
     */
    public function viewDossierSejour(): void
    {
        $this->checkPermRead();

        $sejourId = CView::get("sejour_id", "ref class|CSejour");
        $date     = CView::get("date", "date default|now");

        $compactSuffix = CAppUI::gconf("soins Other vue_condensee_dossier_soins") ? "_compact" : "";
        $fallbackTab   = "dossier_traitement" . $compactSuffix;

        if (CAppUI::gconf("soins dossier_soins tab_prescription_med") && CMediusers::get()->isPraticien()) {
            $fallbackTab = "prescription_sejour";
        }

        $defaultTab       = CView::get("default_tab", "str default|$fallbackTab");
        $popup            = CView::get("popup", "bool default|0");
        $modal            = CView::get("modal", "bool default|0");
        $operationId      = CView::get("operation_id", "ref class|COperation");
        $modePharma       = CView::get("mode_pharma", "bool default|0");
        $modeProtocole    = CView::get("mode_protocole", "bool default|0", true);
        $typePrescription = CView::get("type_prescription", "enum list|pre_admission|sejour|sortie default|sejour");
        $lineGuidOpen     = CView::get("line_guid_open", "str");

        CView::checkin();

        $service = new DossierSoinsService($sejourId, $date, $operationId);
        $service->loadDossierSoins();

        $formTabs = $service->form_tabs;

        $imedsInstalled = CModule::getActive("dPImeds") && CImeds::getTagCIDC(CGroups::loadCurrent());

        $lateObjectifs = $service->getLateObjectifsSoins();

        $sourceLabo = CExchangeSource::get(
            "OxLabo" . CGroups::loadCurrent()->_id,
            CSourceHTTP::TYPE,
            false,
            "OxLaboExchange",
            false
        );

        $this->renderSmarty(
            "inc_dossier_sejour",
            [
                "sejour"                  => $service->getSejour(),
                "patient"                 => $service->getPatient(),
                "date"                    => $date,
                "date_plan_soins"         => $service->getDatePlanSoins(),
                "default_tab"             => $defaultTab,
                "popup"                   => $popup,
                "modal"                   => $modal,
                "operation_id"            => $operationId,
                "mode_pharma"             => $modePharma,
                "is_praticien"            => CAppUI::$user->isPraticien(),
                "mode_protocole"          => $modeProtocole,
                "operation"               => $service->getOperation(),
                "form_tabs"               => $formTabs,
                "late_objectifs"          => $lateObjectifs,
                "isImedsInstalled"        => $imedsInstalled,
                "isPrescriptionInstalled" => $service->isPrescriptionInstalled,
                "type_prescription"       => $typePrescription,
                "line_guid_open"          => $lineGuidOpen,
                "getSourceLabo"           => $sourceLabo->active ? true : false,
            ]
        );
    }
}
