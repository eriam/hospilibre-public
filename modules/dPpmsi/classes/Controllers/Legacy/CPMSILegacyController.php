<?php

/**
 * @package Hospilibre
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\Pmsi\Controllers\Legacy;

use Exception;
use Ox\Core\CApp;
use Ox\Core\CAppUI;
use Ox\Core\CLegacyController;
use Ox\Core\CMbArray;
use Ox\Core\CMbDT;
use Ox\Core\CMbModelNotFoundException;
use Ox\Core\CStoredObject;
use Ox\Core\CView;
use Ox\Core\Module\CModule;
use Ox\Interop\Imeds\CImeds;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Mediusers\CFunctions;
use Hospilibre\Pmsi\CRelancePMSI;
use Hospilibre\Pmsi\PmsiExporter;
use Hospilibre\Pmsi\PMSIService;
use Hospilibre\Pmsi\RelancePMSIService;
use Hospilibre\Pmsi\Services\PMSIRelanceService;
use Hospilibre\System\Forms\CExClassEvent;

/**
 * Contrôleur historique du module PMSI.
 */
class CPMSILegacyController extends CLegacyController
{
    /**
     * Génère l'export CSV des interventions programmées.
     *
     * @throws Exception
     */
    public function exportCsvPlannedOperations(): void
    {
        $this->checkPermRead();

        // Fenêtre temporelle : par défaut de la veille à maintenant.
        $borneMin = CView::get("date_min", "date default|" . CMbDT::date('-1 day'), true);
        $borneMax = CView::get("date_max", "date default|now", true);
        $typesOp  = CView::get("types", "str", true);

        CView::checkin();

        try {
            $exporteur = new PMSIExporter();
            $exporteur->exportOperationsToCsv($borneMin, $borneMax, $typesOp, PMSIExporter::CURRENT_OPERATION);
        } catch (Exception $erreur) {
            CAppUI::stepAjax($erreur->getMessage());
        }

        CApp::rip();
    }

    /**
     * Affiche le dossier de séjour d'un patient.
     *
     * @return void
     * @throws CMbModelNotFoundException
     */
    public function viewStayDossier(): void
    {
        $this->checkPermEdit();

        $patient_id = CView::get('patient_id', 'ref class|CPatient');
        $sejour_id  = CView::get('sejour_id', 'ref class|CSejour');

        CView::checkin();

        $dossier       = (new PMSIService())->getStayDossierPMSI($patient_id, $sejour_id);
        $patient       = $dossier["patient"];
        $sejour        = $dossier["sejour"];
        $sejour_maman  = $dossier["sejour_maman"];
        $naissance_enf = $dossier["naissance_enf"];

        // Récupération des onglets de formulaires si le module correspondant est actif.
        $ongletsFormulaires = [];
        if (CModule::getActive("forms")) {
            $cibles = [
                [
                    "tab_dossier_soins",
                    $sejour,
                ],
            ];

            $ongletsFormulaires = CExClassEvent::getTabEvents($cibles);
        }

        if (is_array($sejour->_ref_suivi_medical)) {
            krsort($sejour->_ref_suivi_medical);
        }

        $this->renderSmarty(
            "inc_vw_dossier_sejour",
            [
                "patient"          => $patient,
                "sejour"           => $sejour,
                "sejour_maman"     => $sejour_maman,
                "naissance"        => $naissance_enf,
                "form_tabs"        => $ongletsFormulaires,
                "canPatients"      => CModule::getCanDo("dPpatients"),
                "hprim21installed" => CModule::getActive("hprim21"),
                "isImedsInstalled" => (CModule::getActive("dPImeds") && CImeds::getTagCIDC(CGroups::loadCurrent())),
            ]
        );
    }

    public function ajax_vw_relances()
    {
        $this->checkPermRead();

        $chir_id     = CView::get("chir_id", "ref class|CMediusers");
        $function_id = CView::get("function_id", "ref class|CFunctions");

        CView::checkin();

        $serviceRelance = new RelancePMSIService();

        // On privilégie le praticien lorsqu'il est fourni, sinon on retombe sur la fonction.
        if ($chir_id) {
            $listeRelances = $serviceRelance->getRelanceFromUserOrFunction("user", $chir_id);
        } else {
            $listeRelances = $serviceRelance->getRelanceFromUserOrFunction("function", $function_id);
        }

        $this->renderSmarty(
            "inc_vw_relances",
            [
                "relances" => $listeRelances,
            ]
        );
    }
}
