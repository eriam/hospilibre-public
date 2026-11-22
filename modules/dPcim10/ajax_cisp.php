<?php
/**
 * @package Hospilibre\Cim10
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CCanDo;
use Ox\Core\CSmartyDP;
use Ox\Core\CView;
use Hospilibre\Admin\CAccessMedicalData;
use Hospilibre\Cabinet\CConsultation;
use Hospilibre\Cim10\Cisp\CCISPChapitre;
use Hospilibre\Cim10\Cisp\CCISPProcedure;

CCanDo::checkRead();

$_keywords  = CView::get("_keywords", "str");
$consult_id = CView::get("consult_id", "ref class|CConsultation");
$mode       = CView::get('mode', 'enum list|consultation|antecedents|pathologie default|consultation');
$ged        = CView::get('ged', 'bool default|0');

CView::checkin();


$consult = new CConsultation();
$consult->load($consult_id);

CAccessMedicalData::logAccess($consult);

// Création du template
$smarty = new CSmartyDP();

$smarty->assign("chapitres", CCISPChapitre::getChapitres());
$smarty->assign("procedures", CCISPProcedure::getProcedures());
$smarty->assign("consult", $consult);
$smarty->assign("mode", $mode);
$smarty->assign("ged", $ged);

$smarty->display("cisp/inc_cisp");
