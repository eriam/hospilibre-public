<?php
/**
 * @package Hospilibre\Hospi
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CCanDo;
use Ox\Core\CSmartyDP;
use Hospilibre\PlanningOp\CSejour;

CCanDo::checkAdmin();

$smarty = new CSmartyDP();
$smarty->assign('type_adm', implode(', ', CSejour::$types));
$smarty->display('vw_import_prestation.tpl');