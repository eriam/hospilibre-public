<?php
/**
 * @package Hospilibre\Ssr
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CCanDo;
use Ox\Core\CSmartyDP;
use Ox\Core\CValue;
use Ox\Core\CView;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Mediusers\CMediusers;

CCanDo::checkRead();

$needle = CValue::post("_executant", "aaa");

CView::enableSlave();

$intervenant = new CMediusers();

$ljoin                        = array();
$ljoin["users"]               = "users.user_id = users_hospilibre.user_id";
$ljoin["functions_hospilibre"] = "functions_hospilibre.function_id = users_hospilibre.function_id";

$where                                 = array();
$where["code_intervenant_cdarr"]       = "IS NOT NULL";
$where["users_hospilibre.actif"]        = "= '1'";
$where["functions_hospilibre.group_id"] = "= '" . CGroups::loadCurrent()->_id . "'";

$order = "users.user_last_name ASC, users.user_first_name ASC";

/** @var CMediusers[] $intervenants */
$intervenants = $intervenant->seek($needle, $where, 100, false, $ljoin, $order);
foreach ($intervenants as &$_intervenant) {
  $_intervenant->loadRefFunction();
  $_intervenant->loadRefIntervenantCdARR();
}

// Création du template
$smarty = new CSmartyDP();

$smarty->assign("intervenants", $intervenants);
$smarty->assign("needle", $needle);
$smarty->assign("nodebug", true);

$smarty->display("inc_do_intervenant_autocomplete");
