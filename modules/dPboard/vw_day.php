<?php
/**
 * @package Hospilibre\Board
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CAppUI;
use Ox\Core\CMbDT;
use Ox\Core\CView;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\System\CSourcePOP;

/**
 * dPboard
 */

CAppUI::requireModuleFile("dPboard", "inc_board");

$date = CView::get("date", "date default|now", true);
$prec = CMbDT::date("-1 day", $date);
$suiv = CMbDT::date("+1 day", $date);
$vue  = CView::get("vue2", "bool default|" . CAppUI::pref("AFFCONSULT", 0), true);
CView::checkin();

global $smarty;
global $prat;
// Variables de templates
$smarty->assign("date", $date);
$smarty->assign("prec", $prec);
$smarty->assign("suiv", $suiv);
$smarty->assign("vue", $vue);
$smarty->assign("user_id", CMediusers::get()->_id);

if (!CAppUI::pref("alternative_display")) {
    $smarty->display("vw_day");
} else {
    $account               = new CSourcePOP();
    $account->object_class = "CMediusers";
    $account->object_id    = $prat->_id;
    $account->loadMatchingObject();
    $account = $account->_id ? $account : null;
    $smarty->assign('account', $account);
    $smarty->display("vw_day_alternative");
}

