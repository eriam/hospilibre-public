<?php
/**
 * @package Hospilibre\Ssr
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CCanDo;
use Ox\Core\CMbDT;
use Ox\Core\CSmartyDP;
use Hospilibre\Ssr\CEvenementSSR;

CCanDo::checkAdmin();

$evenement         = new CEvenementSSR();
$evenement->_debut = CMbDT::dateTime("-1 week");
$evenement->_fin   = CMbDT::dateTime();

$smarty = new CSmartyDP();

$smarty->assign("evenement", $evenement);

$smarty->display("vw_doublons_actes");