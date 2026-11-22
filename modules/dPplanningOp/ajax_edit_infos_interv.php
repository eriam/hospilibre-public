<?php
/**
 * @package Hospilibre\PlanningOp
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CCanDo;
use Ox\Core\CSmartyDP;
use Ox\Core\CValue;
use Hospilibre\Admin\CAccessMedicalData;
use Hospilibre\PlanningOp\COperation;

CCanDo::checkEdit();

$operation_id = CValue::get("operation_id");

$operation = new COperation();
$operation->load($operation_id);

CAccessMedicalData::logAccess($operation);

$smarty = new CSmartyDP();

$smarty->assign("operation", $operation);

$smarty->display("inc_edit_infos_interv");