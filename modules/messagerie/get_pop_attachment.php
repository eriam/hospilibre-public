<?php
/**
 * @package Hospilibre\Messagerie
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CAppUI;
use Ox\Core\CCanDo;
use Ox\Core\CSmartyDP;
use Ox\Core\CView;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Messagerie\CMailAttachments;
use Hospilibre\Messagerie\CPop;
use Hospilibre\Messagerie\CUserMail;
use Hospilibre\System\CSourcePOP;

CCanDo::checkRead();

$user = CMediusers::get();
$mail_id = CView::get("mail_id", 'ref class|CUserMail');
$part = CView::get("part", 'num');

CView::checkin();

$log_pop = new CSourcePOP();
$log_pop->name = "user-pop-".$user->_id;
$log_pop->loadMatchingObject();

if (!$log_pop) {
    CAppUI::stepAjax("Source POP indisponible", UI_MSG_ERROR);
}

if (!$mail_id) {
    CAppUI::stepAjax("CSourcePOP-error-mail_id", UI_MSG_ERROR);
}

$mail = new CUserMail();
$mail->load($mail_id);

if ($mail->_id) {
  $pop = new CPop($log_pop);
  $pop->open();
  $attach  = new CMailAttachments();
  $struct = $pop->structure($mail->uid);
  $parts = explode(".", $part);  //recursive parts
  foreach ($parts as $key=>$value) {
      $struct = $struct->parts[$value];
  }

  $attach->loadFromHeader($struct);
  $attach->part = $part;
  $attach->loadContentFromPop($pop->openPart($mail->uid, $attach->getpartDL()));

  $smarty = new CSmartyDP();
  $smarty->assign("_attachment", $attach);
  $smarty->display("inc_show_attachments.tpl");

  $pop->close();
}