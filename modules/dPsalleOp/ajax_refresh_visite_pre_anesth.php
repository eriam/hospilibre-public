<?php
/**
 * @package Hospilibre\SalleOp
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CSmartyDP;
use Ox\Core\CView;
use Hospilibre\Admin\CAccessMedicalData;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\PlanningOp\COperation;

$operation_id = CView::get("operation_id", 'ref class|COperation');
$callback     = CView::get("callback", 'str');

CView::checkin();

$operation = new COperation;
$operation->load($operation_id);

CAccessMedicalData::logAccess($operation);

$operation->loadRefVisiteAnesth();

$listAnesths = new CMediusers;
$listAnesths = $listAnesths->loadAnesthesistes(PERM_DENY);

$user = CMediusers::get();
$user->isAnesth();
$user->isPraticien();

$smarty = new CSmartyDP;

$smarty->assign("selOp", $operation);
$smarty->assign("listAnesths", $listAnesths);
$smarty->assign("callback"   , $callback);
$smarty->assign("currUser"   , $user);
$smarty->display("inc_visite_pre_anesth.tpl");
