<?php

/**
 * @package Hospilibre\Urgences
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Urgences\Controllers\Legacy;

use Exception;
use Ox\Core\CAppUI;
use Ox\Core\CLegacyController;
use Ox\Core\CMbArray;
use Ox\Core\CMbDT;
use Ox\Core\CStoredObject;
use Ox\Core\CView;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Files\CFile;
use Hospilibre\Files\CFilesCategory;
use Hospilibre\Hospi\CAffectation;
use Hospilibre\Patients\CPatient;
use Hospilibre\PlanningOp\CSejour;
use Hospilibre\Urgences\ExportRPU;
use Hospilibre\Urgences\Services\RPUService;

class CRPUController extends CLegacyController
{
    /**
     * Controller to change consult stay
     *
     * @throws Exception
     */
    public function do_cnsp_hospitalisation(): void
    {
        $this->checkPermRead();

        $sejour_id      = CView::post("sejour_id", "ref class|CSejour");
        $type           = CView::post("type", "str");
        $lit_id         = CView::post("lit_id", "ref class|CLit");
        $uf_soins_id    = CView::post("uf_soins_id", "ref class|CUniteFonctionnelle");
        $uf_medicale_id = CView::post("uf_medicale_id", "ref class|CUniteFonctionnelle");
        $charge_id      = CView::post("charge_id", "ref class|CChargePriceIndicator");
        $affectation_id = CView::post("affectation_id", "ref class|CAffectation");
        $mode_entree    = CView::post("mode_entree", "str");
        $mode_entree_id = CView::post("mode_entree_id", "str");
        $provenance     = CView::post("provenance", "str");
        $date_aff       = CView::post("date_aff", "dateTime default|now");
        $postRedirect   = CView::post("postRedirect", "str");

        // Passage en CNSPH ou revenir en RPU
        $sejour                       = CSejour::findOrNew($sejour_id);
        $sejour->_create_affectations = false;

        // Passage en UHCD ou ATU du séjour
        $sejour->charge_id = $charge_id;
        $sejour->UHCD      = 0;
        $sejour->type      = $type;
        $msg               = $sejour->store();

        CAppUI::setMsg($msg ?: "CSejour-msg-modify", $msg ? UI_MSG_ERROR : UI_MSG_OK);

        // Changement du box sur le RPU
        $rpu                                                          = $sejour->loadRefRPU();
        $rpu->loadRefConsult()->loadRefSejour()->_create_affectations = false;
        $rpu->box_id                                                  = $lit_id;
        $rpu->_store_affectation                                      = false;
        $msg                                                          = $rpu->store();

        CAppUI::setMsg($msg ?: "CRPU-msg-modify", $msg ? UI_MSG_ERROR : UI_MSG_OK);

        // Création de l'affectation
        $affectation = new CAffectation();
        $affectation->load($affectation_id);
        $affectation->sortie = $date_aff;

        $affectation_cut                 = new CAffectation();
        $affectation_cut->sejour_id      = $sejour->_id;
        $affectation_cut->entree         = $date_aff;
        $affectation_cut->sortie         = $sejour->sortie;
        $affectation_cut->lit_id         = $lit_id;
        $affectation_cut->uf_soins_id    = $uf_soins_id;
        $affectation_cut->uf_medicale_id = $uf_medicale_id;
        $affectation_cut->mode_entree    = $mode_entree;
        $affectation_cut->mode_entree_id = $mode_entree_id;
        $affectation_cut->provenance     = $provenance;
        $affectation_cut->uhcd           = 0;
        $affectation_cut->praticien_id   = $affectation->praticien_id ?: $sejour->praticien_id;

        CSejour::$_cutting_affectation = true;

        if ($affectation->_id) {
            $msg = $affectation->store();

            CAppUI::setMsg($msg ?: "CAffectation-msg-modify", $msg ? UI_MSG_ERROR : UI_MSG_OK);
        }

        $msg = $affectation_cut->store();

        CAppUI::setMsg($msg ?: "CAffectation-msg-create", $msg ? UI_MSG_ERROR : UI_MSG_OK);
        CAppUI::js("Control.Modal.refresh();");
    }

    /**
     * Get list of ECG pdf files from category
     *
     * @throws Exception
     */
    public function getListEcgPdfFromCategory(): void
    {
        $this->checkPermRead();

        $category_id = CView::get("category_id", "ref class|CFilesCategory");
        $sejour_id   = CView::get("sejour_id", "ref class|CSejour");
        $tab_id      = CView::get("tab_id", "str");

        CView::checkin();

        $file_cat = CFilesCategory::findOrFail($category_id);
        $sejour   = CSejour::findOrFail($sejour_id);

        $ecg_files = [];

        if ($file_cat->_id && $sejour->_id) {
            $sejour->loadRefPatient();
            $order = "file_date DESC";

            $file                   = new CFile();
            $file->file_category_id = $category_id;

            $file->object_class = "CSejour";
            $file->object_id    = $sejour->_id;
            $ecgsejour          = $file->loadMatchingListEsc($order);

            $file->object_class = "CPatient";
            $file->object_id    = $sejour->_ref_patient->_id;
            $ecgpatient         = $file->loadMatchingListEsc($order);

            $ecg_files = CMbArray::mergeRecursive($ecgpatient, $ecgsejour);

            $this->renderSmarty(
                'ecg/ecg_file_reader',
                [
                    'ecg_files'     => $ecg_files,
                    'category'      => $file_cat,
                    'rpu_sejour_id' => $sejour_id,
                    'tab_id'        => $tab_id
                ]
            );
        }
    }

    /**
     * Display the PDF Document
     *
     * @throws Exception
     */
    public function displayEcgPdf(): void
    {
        $this->checkPermRead();

        $document_id = CView::get("document_id", "ref class|CFile");

        CView::checkin();

        $file = CFile::findOrFail($document_id);

        if ($file->_id && $file->file_type === "application/pdf") {
            $file->streamFile();
        }

        $this->rip();
    }

    /**
     * @throws Exception
     */
    public function dashboard(): void
    {
        $this->checkPermRead();

        CView::checkin();

        $date_min = CMbDT::date('-1 WEEK');
        $date_max = CMbDT::date();

        $this->renderSmarty(
            'vw_rpu_dashboard',
            [
                'date_min' => $date_min,
                'date_max' => $date_max,
            ]
        );
    }

    /**
     * @throws Exception
     */
    public function vwList(): void
    {
        $this->checkPermRead();

        $page        = CView::get('page', 'num default|0');
        $date_max    = CView::get('date_max', 'date default|' . CMbDT::date());
        $date_min    = CView::get('date_min', 'date default|' . CMbDT::date("-1 week", $date_max));
        $patient_id  = CView::get('patient_id', 'ref class|CPatient');
        $patient_ipp = CView::get('patient_ipp', 'str');
        $sent        = CView::get('sent', 'enum list|on|off default|off');
        $nda         = CView::get('nda', 'str');
        $order_col   = CView::get(
            'order_col',
            'enum list|entree|_count_extract_passages|_first_extract_passages default|entree'
        );
        $order_way   = CView::get('order_way', 'enum list|ASC|DESC default|DESC');

        CView::checkin();

        $rpu_service = new RPUService();
        $rpu_service->joinSejour();
        $rpu_service->joinExtract();
        $rpu_service->addSejourDatesFilter($date_min, $date_max);

        if ($patient_ipp) {
            $rpu_service->addPatientIPPFilter($patient_ipp);
        }

        if ($patient_id) {
            $rpu_service->addPatientFilter($patient_id);
        }

        if ($sent === 'on') {
            $rpu_service->addSentFilter();
        }

        if ($nda) {
            $rpu_service->addNDAFilter($nda);
        }

        $rpu_service->computePagination($order_col, $order_way);

        $rpus  = $rpu_service->loadRPUList($page);
        $total = $rpu_service->getTotal();

        /** @var CSejour[] $sejours */
        $sejours = CStoredObject::massLoadFwdRef($rpus, 'sejour_id');
        CSejour::massLoadNDA($sejours);

        /** @var CPatient[] $patients */
        $patients = CStoredObject::massLoadFwdRef($sejours, 'patient_id');
        CPatient::massLoadIPP($patients);

        foreach ($rpus as $_rpu) {
            $_rpu->loadFirstAndLastPassages();
        }

        $this->renderSmarty(
            'inc_RPU_list',
            [
                'rpus'      => $rpus,
                'page'      => $page,
                'step'      => $rpu_service::STEP,
                'total'     => $total,
                'order_way' => $order_way,
                'order_col' => $order_col,
            ]
        );
    }

    /**
     * Export RPU datas
     *
     * @throws Exception
     */
    public function exportRPUDatas(): void
    {
        $this->checkPermRead();

        $number_last_days = CView::get("number_last_days", 'num');
        CView::checkin();

        ExportRPU::exportDatas($number_last_days);
        $this->rip();
    }
}
