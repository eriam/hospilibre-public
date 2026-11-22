<?php
/**
 * @package Hospilibre\PlanningOp
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CCanDo;
use Ox\Core\Module\CModule;
use Ox\Core\CSmartyDP;
use Ox\Core\CView;
use Ox\Interop\Imeds\CImeds;
use Hospilibre\Admin\CAccessMedicalData;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\PlanningOp\COperation;
use Hospilibre\SalleOp\CAnesthPerop;

CCanDo::checkRead();

$operation_id = CView::get("operation_id", "ref class|COperation");

CView::checkin();

$operation = new COperation();
$operation->load($operation_id);

CAccessMedicalData::logAccess($operation);

$operation->loadRefAffectation();
$consult_anesth = $operation->loadRefsConsultAnesth();
$operation->_ref_sejour->loadRefsFwd();

if ($consult_anesth) {
  $consult_anesth->loadRefConsultation();
  $consult_anesth->_ref_consultation->loadRefPraticien();
}

// Récupération de l'utilisateur courant
$currUser = CMediusers::get();
$currUser->isAnesth();

// Chargement des anesthésistes
$listAnesths = $currUser->loadAnesthesistes(PERM_DENY);

// Création du template
$smarty = new CSmartyDP();

$smarty->assign("currUser"               , $currUser);
$smarty->assign("user_id"                , $currUser->_id);
$smarty->assign("listAnesths"            , $listAnesths);
$smarty->assign("isPrescriptionInstalled", CModule::getActive("dPprescription"));
$smarty->assign("isImedsInstalled"       , (CModule::getActive("dPImeds") && CImeds::getTagCIDC(CGroups::loadCurrent())));
$smarty->assign("operation"              , $operation);
$smarty->assign("anesth_perop"           , new CAnesthPerop());
$smarty->assign("create_dossier_anesth"  , 0);
$smarty->display("edit_visite_anesth");
