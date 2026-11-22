<?php
/**
 * @package Hospilibre\Messagerie
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CCanDo;
use Ox\Core\CSmartyDP;
use Ox\Core\CView;
use Hospilibre\Messagerie\CJeeboxLDAPRecipient;
use Hospilibre\Messagerie\CJeeboxLDAPService;

CCanDo::checkRead();

CView::checkin();

$data = $_POST;

$type = $data['address_type'];

$query = new CJeeboxLDAPRecipient($data);

$results = CJeeboxLDAPService::search($query);

$smarty = new CSmartyDP();
$smarty->assign('results', $results);
$smarty->assign('type', $type);
$smarty->display('inc_search_recipients_results.tpl');