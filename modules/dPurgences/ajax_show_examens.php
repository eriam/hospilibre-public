<?php
/**
 * @package Hospilibre\Urgences
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\Module\CModule;
use Ox\Core\CSmartyDP;
use Ox\Core\CValue;
use Hospilibre\Admin\CAccessMedicalData;
use Hospilibre\Cabinet\CConsultation;

$consult_id = CValue::get("consult_id");

$consult = new CConsultation();
$consult->load($consult_id);

CAccessMedicalData::logAccess($consult);

$consult->loadRefsFwd();
$consult->loadRefSejour();

$consult->_ref_patient->loadRefPhotoIdentite();

// Création du template
$smarty = new CSmartyDP("modules/dPcabinet");

$smarty->assign("consult", $consult);
$smarty->assign("readonly", 1);
$smarty->assign("isPrescriptionInstalled", CModule::getActive("dPprescription"));
$smarty->assign("show_header", 0);

$smarty->display("inc_main_consultform");
