<?php
/**
 * @package Hospilibre\CompteRendu
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CCanDo;
use Ox\Core\CMbObject;
use Ox\Core\CSmartyDP;
use Ox\Core\CView;
use Hospilibre\Admin\CAccessMedicalData;
use Hospilibre\Cabinet\CConsultation;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Mediusers\CFunctions;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Patients\CPatient;
use Hospilibre\PlanningOp\COperation;
use Hospilibre\PlanningOp\CSejour;

CCanDo::checkEdit();

$context_guid = CView::get("context_guid", "str");
$user_id      = CView::get("user_id", "str");
$function_id  = CView::get("function_id", "str");
$group_id     = CView::get("group_id", "str");

CView::checkin();

/** @var CMbObject $context */
$context = CMbObject::loadFromGuid($context_guid);

if ($context instanceof CSejour || $context instanceof COperation || $context instanceof CConsultation) {
  CAccessMedicalData::logAccess($context);
}

/** @var CPatient $patient */
$patient = null;

// Document
if ($context->_id) {
  switch ($context->_class) {
    default:
      // Contexte inconnu
      break;
    case "CPatient":
      $patient = $context;
      break;
    case "CConsultAnesth":
    case "CConsultation":
    case "CSejour":
    case "COperation":
      $patient = $context->loadRefPatient();
  }

  if ($patient) {
    foreach ($patient->loadRefsSejours() as $_sejour) {
      $_sejour->loadRefsOperations();
      $_sejour->loadRefsConsultations();
    }
    $patient->loadRefsConsultations();
  }
}
// ModÎ¸le
else {
  if ($user_id) {
    $context = CMediusers::get($user_id);
  }
  elseif ($function_id) {
    $context = new CFunctions();
    $context->load($function_id);
  }
  elseif ($group_id) {
    $context = new CGroups();
    $context->load($group_id);
  }
}

$smarty = new CSmartyDP();

$smarty->assign("context", $context);
$smarty->assign("patient", $patient);

$smarty->display("vw_select_image");