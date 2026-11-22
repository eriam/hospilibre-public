<?php
/**
 * @package Hospilibre\Ssr
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CCanDo;
use Ox\Core\CSmartyDP;
use Ox\Core\CView;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Ssr\CActiviteCsARR;
use Hospilibre\Ssr\CFavoriCsARR;
use Hospilibre\Ssr\CHierarchieCsARR;

CCanDo::checkRead();

CView::checkin();

$user = CMediusers::get();

$profiles = array(
  'user' => array(
    'user_id' => $user->_id,
    'user'    => $user,
    'codes'   => CFavoriCsARR::findCodes($user)
  )
);

$codes = CActiviteCsARR::findCodes('');

foreach ($codes as $code) {
  $code->isFavori($user);
}

$smarty = new CSmartyDP();
$smarty->assign('profiles', $profiles);
$smarty->assign('object_class', '');
$smarty->assign('object_id', '');
$smarty->assign("codes", $codes);
$smarty->assign('user', $user);
$smarty->assign('chapters', CHierarchieCsARR::getChapters());
$smarty->assign('hide_selector', 1);
$smarty->display("vw_csarr");
