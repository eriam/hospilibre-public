<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Board\Controllers\Legacy;

use Ox\Core\CAppUI;
use Ox\Core\CLegacyController;
use Ox\Core\CMbDT;
use Ox\Core\CStoredObject;
use Ox\Core\CView;
use Hospilibre\Cabinet\CConsultAnesth;
use Hospilibre\Cabinet\CConsultation;
use Hospilibre\CompteRendu\CCompteRendu;
use Hospilibre\CompteRendu\CStatutCompteRendu;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\PlanningOp\COperation;
use Hospilibre\PlanningOp\CSejour;

class TdbSecretaireLegacyController extends CLegacyController
{
    /**
     * @throws \Exception
     */
    public function tdbSecretaire(): void
    {
        $this->checkPermRead();
        $chir_ids = CView::get("chir_ids", 'str', true);
        $date_min  = CView::get('date_min', array('date', 'default' => CMbDT::date("-15 day")), true);
        CView::checkin();

        $user = new CMediusers();
        $praticiens = [];
        if ($chir_ids && $chir_ids[0] != -1) {
            $where       = [
                "user_id" => $user->getDS()->prepareIn($chir_ids),
            ];
            $praticiens = $user->loadList($where);
        }


        $this->renderSmarty(
            'vw_tdb_secretaire',
            [
                'praticiens' => $praticiens,
                'date_min'   => $date_min,
            ]
        );
    }

    /**
     * @throws \Exception
     */
    public function getListDocuments(): void
    {
        $this->checkPermRead();
        $chir_ids = CView::get("chir_ids", 'str');
        $date_min = CView::get('date_min', array('date', 'default' => CMbDT::date("-15 day")));

        CView::checkin();
        CView::enforceSlave();

        $cr = new CCompteRendu();
        if ($chir_ids && count($chir_ids)) {
            $where       = [
                "signataire_id" => $cr->getDS()->prepareIn($chir_ids),
            ];
        } else {
            $user = CMediusers::get();
            $praticiens = $user->loadPraticiens(PERM_EDIT, null, null, false, true, 1);
            $where = [
                "signataire_id" => $cr->getDS()->prepareIn(array_keys($praticiens)),
            ];
        }
        $crs = $cr->loadList($where, 'creation_date DESC', 100);
        CStoredObject::massLoadFwdRef($crs, "object_id");
        CStoredObject::massLoadFwdRef($crs, "content_id");
        $affichageDocs = [];
        $total = [
            "attente_validation_praticien" => 0,
            "a_corriger" => 0,
            "envoye" => 0,
            "a_envoyer" => 0,
        ];

        /** @var CCompteRendu $_cr */
        foreach ($crs as $_cr) {
            $context = $_cr->loadTargetObject();
            $date = 0;
            switch ($context->_class) {
                default:
                    $context_cancelled = false;
                    break;
                case "CConsultation":
                    $context_cancelled = $context->annule;
                    $date = $context->creation_date < $date_min;
                    break;
                case "CSejour":
                    /** @var  $context CSejour */
                    $context_cancelled = $context->annule;
                    $date = $date_min > $context->_date_sortie_prevue;
                    break;
                case "CConsultAnesth":
                    /** @var  $context CConsultAnesth */
                    $context_cancelled = $context->loadRefConsultation()->annule;
                    $date = $context->_date_consult < $date_min;
                    break;
                case "COperation":
                    /** @var  $context COperation */
                    $date = $context->date < $date_min;
                    $context_cancelled = $context->annulee;
            }

            if ($_cr->isAutoLock() || $context_cancelled || $date) {
                unset($crs[$_cr->_id]);
                continue;
            }
            $_cr->_ref_patient = $_cr->getIndexablePatient();
            $_cr->loadLastRefStatutCompteRendu();

            if ($_cr->_ref_last_statut_compte_rendu->_id) {
                $_cr->_ref_last_statut_compte_rendu->loadRefUtilisateur();
                $_cr->_ref_last_statut_compte_rendu->getDelaiAttenteCorrection();
            } else {
                unset($crs[$_cr->_id]);
                continue;
            }
            $cat_id = $_cr->file_category_id ?: 0;
            if (
                $_cr->_ref_last_statut_compte_rendu->statut == "brouillon" ||
                $_cr->_ref_last_statut_compte_rendu->statut == "attente_correction_secretariat"
            ) {
                $affichageDocs["a_corriger"][$cat_id]["items"][$_cr->nom . "-$_cr->_guid"] = $_cr;
                $total["a_corriger"] += 1;
            } else {
                $affichageDocs[$_cr->_ref_last_statut_compte_rendu->statut][$cat_id]["items"]
                [$_cr->nom . "-$_cr->_guid"] = $_cr;
                $total[$_cr->_ref_last_statut_compte_rendu->statut] += 1;
            }


            if (!isset($affichageDocs[$_cr->_ref_last_statut_compte_rendu->statut][$cat_id]["name"])) {
                if (
                    $_cr->_ref_last_statut_compte_rendu->statut == "brouillon" ||
                    $_cr->_ref_last_statut_compte_rendu->statut == "attente_correction_secretariat"
                ) {
                    $affichageDocs["a_corriger"][$cat_id]["name"] = $cat_id ? $_cr->_ref_category->nom : CAppUI::tr(
                        "CFilesCategory.none"
                    );
                } else {
                    $affichageDocs[$_cr->_ref_last_statut_compte_rendu->statut][$cat_id]["name"] =
                        $cat_id ? $_cr->_ref_category->nom : CAppUI::tr(
                            "CFilesCategory.none"
                        );
                }
            }
        }

        $this->renderSmarty(
            'inc_tdb_secretaire',
            [
                "affichageDocs" => $affichageDocs,
                "total" => $total,
            ]
        );
    }
}
