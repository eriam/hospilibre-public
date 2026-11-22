<?php
/**
 * @package Hospilibre\Forms
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CCanDo;
use Ox\Core\CSmartyDP;
use Ox\Core\CStoredObject;
use Ox\Core\CView;
use Hospilibre\System\CExchangeSource;
use Hospilibre\System\CSourceSMTP;
use Hospilibre\System\Forms\CExClass;

CCanDo::checkEdit();

$ex_class_id = CView::get("ex_class_id", "ref class|CExClass notNull");

CView::checkin();

$source_smtp = CExchangeSource::get("system-message", CSourceSMTP::TYPE);

$ex_class = new CExClass();
$ex_class->load($ex_class_id);

$notifications = $ex_class->loadRefsNotifications();
CStoredObject::massLoadFwdRef($notifications, "predicate_id");
CStoredObject::massLoadFwdRef($notifications, "target_user_id");

foreach ($notifications as $_notification) {
  $_notification->loadRefPredicate()->loadView();
  $_notification->loadRefTargetUser();
}

$smarty = new CSmartyDP();
$smarty->assign("notifications", $notifications);
$smarty->assign("ex_class", $ex_class);
$smarty->assign("source_smtp", $source_smtp);
$smarty->display("inc_list_ex_field_notification.tpl");
