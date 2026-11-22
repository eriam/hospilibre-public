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
use Hospilibre\PlanningOp\COperation;

$operation_id = CView::get("operation_id", 'ref class|COperation');

CView::checkin();

$operation = new COperation();
$operation->load($operation_id);

CAccessMedicalData::logAccess($operation);

$operation->loadRefsAnesthPerops();

// Création du template
$smarty = new CSmartyDP();
$smarty->assign("operation", $operation);
$smarty->display("inc_list_anesth_perops.tpl");
