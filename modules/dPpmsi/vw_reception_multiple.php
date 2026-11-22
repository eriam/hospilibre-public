<?php
/**
 * @package Hospilibre\Pmsi
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CCanDo;
use Ox\Core\CSmartyDP;
use Hospilibre\PlanningOp\CSejour;

CCanDo::checkRead();

$smarty = new CSmartyDP();
$smarty->assign('filter', new CSejour());
$smarty->display('reception_multiple/vw_reception_multiple');