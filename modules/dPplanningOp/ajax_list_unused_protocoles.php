<?php
/**
 * @package Hospilibre\PlanningOp
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CCanDo;
use Ox\Core\CMbArray;
use Ox\Core\CSmartyDP;
use Ox\Core\CView;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\PlanningOp\CProtocole;

CCanDo::checkAdmin();

$chir_id     = CView::get("chir_id", "ref class|CMediusers", true);
$function_id = CView::get("function_id", "ref class|CFunctions", true);

CView::checkin();
CView::enforceSlave();

$protocole = new CProtocole();

$ds = $protocole->getDS();

$where = [
  "operations.operation_id" => "IS NULL"
];

$ljoin = [
  "operations" => "operations.protocole_id = protocole.protocole_id",
];

if ($chir_id) {
  $where["protocole.chir_id"] = $ds->prepare("= ?", $chir_id);
}
elseif ($function_id) {
  $where["protocole.function_id"] = $ds->prepare("= ?", $function_id);
}
else {
  $where["functions_hospilibre.group_id"] = $ds->prepare("= ?", CGroups::loadCurrent()->_id);
  $ljoin["users_hospilibre"]              = "users_hospilibre.user_id = protocole.chir_id";
  $ljoin["functions_hospilibre"]          = "functions_hospilibre.function_id = users_hospilibre.function_id
  OR functions_hospilibre.function_id = protocole.function_id";
}

$protocoles = $protocole->loadList($where, null, null, "protocole.protocole_id", $ljoin);

CMbArray::pluckSort($protocoles, SORT_ASC, "_view");

$smarty = new CSmartyDP();

$smarty->assign("protocoles", $protocoles);

$smarty->display("inc_list_unused_protocoles");
