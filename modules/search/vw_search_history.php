<?php
/**
 * @package Hospilibre\Search
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CCanDo;
use Ox\Core\CSmartyDP;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Search\CSearch;

CCanDo::checkRead();

$user = CMediusers::get();

$contextes = CSearch::$contextes;


$smarty = new CSmartyDP();
$smarty->assign('contextes', $contextes);
$smarty->display("vw_search_history.tpl");


