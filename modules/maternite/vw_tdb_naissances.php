<?php
/**
 * @package Hospilibre\Maternite
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CCanDo;
use Ox\Core\CSmartyDP;
use Ox\Core\CView;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Maternite\CNaissance;
use Hospilibre\PlanningOp\CSejour;

CCanDo::checkRead();
$date = CView::get("date", "date default|now");
$page = CView::get("page", "num default|0");
CView::checkin();

$group = CGroups::loadCurrent();

$filter            = new CSejour();
$filter->_date_min = "$date 00:00:00";
$filter->_date_max = "$date 23:59:59";

$smarty = new CSmartyDP();
$smarty->assign("filter"   , $filter);
$smarty->assign("naissance", new CNaissance());
$smarty->assign("page"     , $page);
$smarty->display("inc_tdb_naissances");
