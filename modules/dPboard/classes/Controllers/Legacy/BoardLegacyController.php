<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Board\Controllers\Legacy;

use Exception;
use Ox\Core\CAppUI;
use Ox\Core\CLegacyController;
use Ox\Core\CMbArray;
use Ox\Core\CMbDT;
use Ox\Core\CSQLDataSource;
use Ox\Core\CStoredObject;
use Ox\Core\CView;
use Hospilibre\Admin\CUser;
use Hospilibre\Admissions\CSejourLoader;
use Hospilibre\Cabinet\CConsultAnesth;
use Hospilibre\Cabinet\CConsultation;
use Hospilibre\CompteRendu\CCompteRendu;
use Hospilibre\CompteRendu\CStatutCompteRendu;
use Hospilibre\Hospi\CObservationMedicale;
use Hospilibre\Hospi\CService;
use Hospilibre\Mediusers\CDiscipline;
use Hospilibre\Mediusers\CFunctions;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Mpm\CPrescriptionLineMedicament;
use Hospilibre\Mpm\CPrescriptionLineMix;
use Hospilibre\PlanningOp\COperation;
use Hospilibre\PlanningOp\CSejour;
use Hospilibre\Prescription\CPrescription;
use Hospilibre\Prescription\CPrescriptionLineElement;
use Hospilibre\System\CSourcePOP;

class BoardLegacyController extends CLegacyController
{
    /**
     * @throws Exception
     */
    public function ajax_list_documents(): void
    {
        $this->checkPermRead();

        $chir_id       = CView::get("chir_id", "ref class|CMediusers");
        $statut        = CView::get("statut", 'str default|');
        $vue_praticien = CView::get("vue_praticien", 'bool default|1');
        $function_id   = CView::get("function_id", "ref class|CFunctions");

        CView::checkin();
        CView::enforceSlave();

        $cr       = new CCompteRendu();
        $users_ids    = [];
        if ($chir_id) {
            $user = CMediusers::get($chir_id);
            $users_ids = [$user->_id];
        } elseif ($function_id) {
            $function = CFunctions::findOrFail($function_id);
            $function->loadRefsUsers();
            $users_ids = CMbArray::pluck($function->_ref_users, '_id');
        }

        $date_limite = CMbDT::date("-30 days");

        $where = [
            "signataire_id" => CSQLDataSource::prepareIn($users_ids),
            "creation_date" => $cr->getDS()->prepare(">= ?", $date_limite),
        ];

        if (!CAppUI::pref("show_all_docs")) {
            $where["signature_mandatory"] = $cr->getDS()->prepare("= '1'");
        }

        $crs = $cr->loadList($where, 'creation_date DESC', 100);

        CStoredObject::massLoadFwdRef($crs, "object_id");
        CStoredObject::massLoadFwdRef($crs, "content_id");

        $affichageDocs = [];
        /** @var CCompteRendu $_cr */
        foreach ($crs as $_cr) {
            $context = $_cr->loadTargetObject();
            switch ($context->_class) {
                default:
                    $context_cancelled = false;
                    break;
                case "CConsultation":
                case "CSejour":
                    /** @var  $context CSejour | CConsultation */
                    $context_cancelled = $context->annule;
                    break;
                case "CConsultAnesth":
                    /** @var  $context CConsultAnesth */
                    $context_cancelled = $context->loadRefConsultation()->annule;
                    break;
                case "COperation":
                    /** @var  $context COperation */
                    $context_cancelled = $context->annulee;
            }

            if ($_cr->isAutoLock() || $context_cancelled) {
                unset($crs[$_cr->_id]);
                continue;
            }

            $_cr->_ref_patient = $_cr->getIndexablePatient();
            $_cr->loadLastRefStatutCompteRendu();

            if ($_cr->_ref_last_statut_compte_rendu) {
                $_cr->_ref_last_statut_compte_rendu->loadRefUtilisateur();
                if ($statut) {
                    if ($_cr->_ref_last_statut_compte_rendu->statut != $statut) {
                        unset($crs[$_cr->_id]);
                        continue;
                    }
                }
                if ($vue_praticien && $_cr->_ref_last_statut_compte_rendu->statut != "attente_validation_praticien") {
                    unset($crs[$_cr->_id]);
                    continue;
                }
            }

            $cat_id = $_cr->file_category_id ?: 0;

            $affichageDocs[$cat_id]["items"][$_cr->nom . "-$_cr->_guid"] = $_cr;
            if (!isset($affichageDocs[$cat_id]["name"])) {
                $affichageDocs[$cat_id]["name"] = $cat_id ? $_cr->_ref_category->nom : CAppUI::tr(
                    "CFilesCategory.none"
                );
            }
        }

        foreach ($affichageDocs as $categorie => $docs) {
            CMbArray::pluckSort($affichageDocs[$categorie]['items'], SORT_DESC, "creation_date");
        }

        $this->renderSmarty(
            'inc_list_documents',
            [
                'affichageDocs' => $affichageDocs,
                'crs'           => $crs,
                'statut'        => $statut,
                'vue_praticien' => $vue_praticien,
            ]
        );
    }

    public function sejoursOtherResponsable(): void
    {
        $ds = CSQLDataSource::get("std");
        // get
        $user             = CUser::get();
        $date             = CView::get("date", "date default|" . CMbDT::date(), true);
        $board            = CView::get("board", "bool default|0");
        $praticien_id_sel = CView::get("pratSel", "ref class|CMediusers default|" . $user->_id);
        $function_id_sel  = CView::get("functionSel", "ref class|CFunctions");

        CView::checkin();

        $date_min = $date . " 00:00:00";
        $date_max = $date . " 23:59:59";

        $userSel  = new CMediusers();
        $function = new CFunctions();

        if ($praticien_id_sel) {
            // Si un praticien est sélectionné, filtre sur le praticien
            $print_content_class = "CMediusers";
            $print_content_id    = $praticien_id_sel;

            $userSel->load($praticien_id_sel);
            $users = [$userSel];
        } elseif ($function_id_sel) {
            // Si un cabinet est sélectionné, filtre sur le cabinet
            $print_content_class = "CFunctions";
            $print_content_id    = $function_id_sel;

            $function->load($function_id_sel);
            $users = $function->loadRefsUsers();
        }

        $sejour       = new CSejour();
        $prescription = new CPrescription();
        $observation  = new CObservationMedicale();

        $where_prescription_line = [
            "praticien_id" => CSQLDataSource::prepareIn(CMbArray::pluck($users, '_id')),
            'debut'        => $ds->prepareBetween(CMbDT::date('-1 month', $date), $date),
        ];
        $where_observation       = [
            "user_id" => CSQLDataSource::prepareIn(CMbArray::pluck($users, '_id')),
            'date'    => $ds->prepareBetween(CMbDT::dateTime('-1 month', $date_min), $date_max),
        ];

        $prescriptions_ids = (new CPrescriptionLineElement())->loadColumn(
            "prescription_id",
            $where_prescription_line
        );
        if (CPrescription::isMPMActive()) {
            $prescriptions_ids = array_merge(
                $prescriptions_ids,
                (new CPrescriptionLineMedicament())->loadColumn(
                    "prescription_id",
                    $where_prescription_line
                )
            );

            $where_prescription_line['date_debut'] = $where_prescription_line['debut'];
            unset($where_prescription_line['debut']);

            $prescriptions_ids = array_merge(
                $prescriptions_ids,
                (new CPrescriptionLineMix())->loadColumn("prescription_id", $where_prescription_line)
            );
        }

        $where_prescription = [
            "prescription_id" => CSQLDataSource::prepareIn($prescriptions_ids),
            "object_class"    => "= 'CSejour'",
        ];

        $sejours_ids = $prescription->loadColumn("object_id", $where_prescription);

        //Récupération des séjours liés Î° une observation
        $sejours_ids = array_merge($sejours_ids, $observation->loadColumn("sejour_id", $where_observation));

        //Filtre des séjours ayant le mÎºme responsable, et au moment de la date sélectionnée
        $where                 = [];
        $where["praticien_id"] = CSQLDataSource::prepareNotIn(CMbArray::pluck($users, '_id'));
        $where["sejour_id"]    = $ds->prepareIn($sejours_ids);
        $sejours_ids           = $sejour->loadIds($where);
        $sejours_ids           = array_unique($sejours_ids);

        $sejours = $sejour->loadAll($sejours_ids);
        $sejours = CSejourLoader::loadSejoursForSejoursView($sejours, $users, $date, false);

        $this->renderSmarty(
            'inc_list_sejours_global',
            [
                "date"                  => $date,
                "praticien"             => $userSel,
                "sejours"               => $sejours,
                "board"                 => $board,
                "service"               => new CService(),
                "service_id"            => null,
                "etats_patient"         => [],
                "show_affectation"      => false,
                "function"              => $function,
                "sejour_id"             => null,
                "show_full_affectation" => true,
                "only_non_checked"      => false,
                "print"                 => false,
                "_sejour"               => new CSejour(),
                'ecap'                  => false,
                'services_selected'     => [],
                'visites'               => [],
                "discipline"            => new CDiscipline(),
                "lite_view"             => true,
                "print_content_class"   => $print_content_class,
                "print_content_id"      => $print_content_id,
                "allow_edit_cleanup"    => 0,
                "tab_to_update"         => "tab-autre-responsable",
                "my_patient"            => false,
                "count_my_patient"      => 0,
            ],
            'modules/soins'
        );
    }

    public function askCorrection(): void
    {
        $compte_rendu_id = CView::get("compte_rendu_id", "ref class|CCompteRendu");
        CView::checkin();
        $statut_compte_rendu                  = new CStatutCompteRendu();
        $statut_compte_rendu->compte_rendu_id = $compte_rendu_id;
        $statut_compte_rendu->statut          = "attente_correction_secretariat";
        $statut_compte_rendu->datetime        = CMbDT::dateTime();
        $statut_compte_rendu->user_id         = CMediusers::get()->_id;

        $this->renderSmarty(
            'vw_add_commentaire',
            [
                'statut' => $statut_compte_rendu,

            ]
        );
    }

    public function ajax_tabs_prescription()
    {
        $this->checkPermRead();

        $chirSel     = CView::get("chirSel", "ref class|CMediusers", true);
        $date        = CView::get("date", "date default|now", true);
        $function_id = CView::get("function_id", "ref class|CFunctions", true);

        CView::checkin();

        $this->renderSmarty(
            'inc_tabs_prescription',
            [
                'date'        => $date,
                'chirSel'     => $chirSel,
                'function_id' => $function_id,

            ]
        );
    }

    public function ajax_worklist()
    {
        $this->checkPermRead();

        // Récupération des paramÎ¸tres
        $chirSel     = CView::get("chirSel", "ref class|CMediusers", true);
        $date        = CView::get("date", "date default|now", true);
        $function_id = CView::get("functionSel", "ref class|CFunctions", true);

        CView::checkin();

        $account               = new CSourcePOP();
        $account->object_class = "CMediusers";
        $account->object_id    = $chirSel;
        $account->loadMatchingObject();

        $this->renderSmarty(
            'inc_worklist',
            [
                'account'     => $account,
                'date'        => $date,
                'chirSel'     => $chirSel,
                'function_id' => $function_id,

            ]
        );
    }
}
