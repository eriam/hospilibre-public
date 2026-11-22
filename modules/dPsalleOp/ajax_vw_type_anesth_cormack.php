<?php
/**
 * @package Hospilibre\SalleOp
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CCanDo;
use Ox\Core\CSmartyDP;
use Ox\Core\CView;
use Hospilibre\Admin\CAccessMedicalData;
use Hospilibre\PlanningOp\COperation;

CCanDo::checkRead();
$intervention_id = CView::get("intervention_id", "ref class|COperation");
CView::checkin();

$intervention = COperation::findOrNew($intervention_id);
CAccessMedicalData::logAccess($intervention);

$consult_anesth = $intervention->loadRefsConsultAnesth();

$smarty = new CSmartyDP();
$smarty->assign("selOp"          , $intervention);
$smarty->assign("consult_anesth" , $consult_anesth);
$smarty->display("inc_vw_type_anesth_cormack");
