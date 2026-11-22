<?php
/**
 * @package Hospilibre\Cabinet
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CCanDo;
use Ox\Core\CMbDT;
use Ox\Core\CSmartyDP;
use Hospilibre\Cabinet\CPlageconsult;
use Hospilibre\Mediusers\CMediusers;

CCanDo::checkRead();
// Current user
$mediuser = CMediusers::get();

// Filter
$filter = new CPlageconsult();
$filter->_date_min  = CMbDT::date("last month");
$filter->_date_max  = CMbDT::date();

$functions = CMediusers::loadFonctions(PERM_EDIT, null, "cabinet");
$users = $mediuser->loadProfessionnelDeSante();

$smarty = new CSmartyDP();

$smarty->assign("filter"    , $filter);
$smarty->assign("users"     , $users);
$smarty->assign("functions" , $functions);

$smarty->display("vw_stats.tpl");
