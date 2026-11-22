<?php
/**
 * @package Hospilibre\Hospi
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CCanDo;
use Ox\Core\CSmartyDP;
use Ox\Core\CView;
use Hospilibre\Hospi\CAffectation;

CCanDo::checkEdit();

$affectation_id = CView::get("affectation_id", "ref class|CAffectation");

CView::checkin();

$affectation = new CAffectation();
$affectation->load($affectation_id);

$affectation->loadRefEtablissementTransfert();

// Création de template
$smarty = new CSmartyDP();

$smarty->assign("affectation", $affectation);

$smarty->display("inc_select_etab_externe.tpl");