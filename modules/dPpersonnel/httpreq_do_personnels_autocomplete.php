<?php
/**
 * @package Hospilibre\Personnel
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CMbArray;
use Ox\Core\CSmartyDP;
use Ox\Core\CSQLDataSource;
use Ox\Core\CView;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Hospi\CAffectationUserService;
use Hospilibre\Mediusers\CMediusers;

$keywords              = CView::post("_view", "str");
$use_personnel_affecte = CView::post("use_personnel_affecte", "bool default|0");
$service_id            = CView::post("service_id", "ref class|CService");
CView::checkin();

$ljoin = array();
$where = array();

$group                        = CGroups::loadCurrent();
$ljoin["functions_hospilibre"] = "functions_hospilibre.function_id = users_hospilibre.function_id";

// Inclusion des fonctions secondaires
$ljoin["secondary_function"] = "secondary_function.user_id = users_hospilibre.user_id";
$ljoin[]                     = "functions_hospilibre AS sec_fnc_mb ON sec_fnc_mb.function_id = secondary_function.function_id";
$where[]                     = "functions_hospilibre.group_id = '$group->_id' OR sec_fnc_mb.group_id = '$group->_id'";

if ($use_personnel_affecte && $service_id) {
  $affectations_user                = CAffectationUserService::listUsersService($service_id);
  $users_ids                        = CMbArray::pluck($affectations_user, "_ref_user", "user_id");
  $where["users_hospilibre.user_id"] = CSQLDataSource::prepareIn($users_ids);
}

$limit = $keywords ? 150 : 50;

$user    = new CMediusers();
$matches = $user->seek($keywords, $where, $limit, false, $ljoin, null, "users_hospilibre.user_id");
$order_view = CMbArray::pluck($matches, "_view");
array_multisort($order_view, SORT_ASC, $matches);

// Création du template
$smarty = new CSmartyDP();
$smarty->assign("keywords", $keywords);
$smarty->assign("matches", $matches);
$smarty->assign("nodebug", true);
$smarty->display("httpreq_do_personnels_autocomplete.tpl");
