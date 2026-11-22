<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\Maternite\Controllers\Legacy;

use Ox\Core\CApp;
use Ox\Core\CAppUI;
use Ox\Core\CCanDo;
use Ox\Core\CLegacyController;
use Ox\Core\CMbDT;
use Ox\Core\CView;
use Hospilibre\Admin\CAccessMedicalData;
use Hospilibre\Admin\CUser;
use Hospilibre\Cabinet\CConsultation;
use Hospilibre\Hospi\CUniteFonctionnelle;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\PlanningOp\CModeEntreeSejour;
use Hospilibre\PlanningOp\CSejour;
use Hospilibre\System\CMergeLog;
use Throwable;

class CHospitalizeController extends CLegacyController
{
    /**
     * Prepare et affiche le formulaire d'hospitalisation de la patiente.
     */
    public function ajax_hospitalize(): void
    {
        CCanDo::checkEdit();

        $consultationId = CView::get("consult_id", "ref class|CConsultation");

        CView::checkin();

        $consultation = new CConsultation();
        $consultation->load($consultationId);

        CAccessMedicalData::logAccess($consultation);

        $stay    = $consultation->loadRefSejour();
        $patient = $consultation->loadRefPatient();

        $stay->loadRefPraticien();

        // Une sage-femme ne peut pas etre praticien responsable du sejour : on remet a zero.
        if ($stay->_ref_praticien->isSageFemme()) {
            $stay->praticien_id   = "";
            $stay->_ref_praticien = new CMediusers();
        }

        $useCustomModeEntree = CAppUI::conf("dPplanningOp CSejour use_custom_mode_entree");
        $entryModes          = CModeEntreeSejour::listModeEntree($stay->group_id);

        // Mode d'entree 8 (transfert) selon la configuration.
        if ($useCustomModeEntree && count($entryModes)) {
            foreach ($entryModes as $_entryMode) {
                if ($_entryMode->code == "8") {
                    $stay->mode_entree_id = $_entryMode->_id;
                    break;
                }
            }
        } else {
            $stay->mode_entree = "8";
        }

        $stay->uf_soins_id = CAppUI::gconf("maternite placement uf_soins_id_dhe");
        $stay->type        = "comp";

        $collisions     = $stay->getCollisions();
        $collisionCount = count($collisions);

        $futureStays      = [];
        $collidingStay    = null;
        $mergeCheckResult = null;

        if ($collisionCount == 1) {
            $collidingStay = current($collisions);
            $collidingStay->loadRefPraticien();

            // On teste si la fusion est possible ; le message d'erreur eventuel est conserve.
            try {
                $stay->checkMerge($collisions);
                $mergeCheckResult = null;
            } catch (Throwable $exception) {
                $mergeCheckResult = $exception->getMessage();
            }
        } else {
            $filter = [
                "entree_reelle" => "IS NULL",
                "sejour_id"     => "!= '$stay->_id'",
                "patient_id"    => "= '$patient->_id'",
                "grossesse_id"  => "IS NOT NULL",
                "annule"        => "= '0'",
            ];

            /** @var CSejour[] $futureStays */
            $futureStays = $stay->loadList($filter, "entree DESC");

            foreach ($futureStays as $_futureStay) {
                $_futureStay->loadRefPraticien()->loadRefFunction();
            }
        }

        $this->renderSmarty(
            'inc_hospitalize',
            [
                'sejour'           => $stay,
                'modes_entree'     => $entryModes,
                'count_collision'  => $collisionCount,
                'sejours_futur'    => $futureStays,
                'sejour_collision' => $collidingStay,
                'check_merge'      => $mergeCheckResult,
                'affectations'     => [],
                'ufs'              => CUniteFonctionnelle::getUFs($stay),
            ]
        );
    }

    public function hospitalizeParturiente()
    {
        $this->checkPermRead();

        $stayId       = CView::post("sejour_id", "ref class|CSejour");
        $stayMergeId  = CView::post("sejour_id_merge", "ref class|CSejour");
        $practitioner = CView::post("praticien_id", "ref class|CMediusers");
        $careUf       = CView::post("uf_soins_id", "ref class|CUniteFonctionnelle");
        $entryMode    = CView::post("mode_entree", "str");
        $entryModeId  = CView::post("mode_entree_id", "ref class|CModeEntreeSejour");
        $atnc         = CView::post("ATNC", "bool");

        CView::checkin();

        $stay = CSejour::findOrFail($stayId);

        $stay->_create_affectations = false;
        $stay->_apply_sectorisation = false;

        $stay->type           = "comp";
        $stay->praticien_id   = $practitioner;
        $stay->uf_soins_id    = $careUf;
        $stay->mode_entree    = $entryMode;
        $stay->mode_entree_id = $entryModeId;

        if ($atnc !== null) {
            $stay->ATNC = $atnc;
        }

        // Aucun mode d'entree fourni : on applique la valeur par defaut (8 = transfert).
        if (!$stay->mode_entree && !$stay->mode_entree_id) {
            $useCustomModeEntree = CAppUI::conf("dPplanningOp CSejour use_custom_mode_entree");
            $entryModes          = CModeEntreeSejour::listModeEntree($stay->group_id);

            if ($useCustomModeEntree && count($entryModes)) {
                foreach ($entryModes as $_entryMode) {
                    if ($_entryMode->code == "8") {
                        $stay->mode_entree_id = $_entryMode->_id;
                        break;
                    }
                }
            } else {
                $stay->mode_entree = "8";
            }
        }

        if ($stayMergeId) {
            $stayToMerge = CSejour::findOrFail($stayMergeId);

            $stayToMerge->_create_affectations = false;
            $stayToMerge->_apply_sectorisation = false;

            foreach ($stayToMerge->loadRefsAffectations() as $_affectation) {
                $_affectation->delete();
            }

            // On reporte la duree du sejour absorbe sur la sortie prevue du sejour cible.
            $lengthInDays        = CMbDT::daysRelative($stayToMerge->entree, $stayToMerge->sortie);
            $stay->sortie_prevue = CMbDT::dateTime("+$lengthInDays days", $stay->entree_prevue);

            $mergeLog = CMergeLog::logStart(CUser::get()->_id, $stay, [$stayToMerge], false);

            try {
                $stay->merge([$stayToMerge], false, $mergeLog);
                $mergeLog->logEnd();
            } catch (Throwable $exception) {
                $mergeLog->logFromThrowable($exception);

                CAppUI::setMsg($exception->getMessage(), UI_MSG_ERROR);
                echo CAppUI::getMsg();
                CApp::rip();
            }

            CAppUI::setMsg("CSejour-Sejours merged", UI_MSG_OK);
        } else {
            $storeError = $stay->store();

            CAppUI::setMsg(
                $storeError ?: CAppUI::tr("CSejour-msg-modify"),
                $storeError ? UI_MSG_ALERT : UI_MSG_OK
            );
        }

        echo CAppUI::getMsg();

        CAppUI::callbackAjax("Control.Modal.close");
        CAppUI::callbackAjax("Sejour.editModal", $stay->_id);
    }
}
