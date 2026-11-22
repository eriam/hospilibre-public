<?php
/**
 * @package Hospilibre\Labo
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CCanDo;
use Ox\Core\CSmartyDP;
use Ox\Core\CValue;
use Hospilibre\Labo\CPrescriptionLabo;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Patients\CPatient;

CCanDo::checkRead();

$user = CMediusers::get();

$patient_id = CValue::getOrSession("patient_id");

$prescription_labo_id = CValue::getOrSession("prescription_labo_id");

if (!$patient_id) {
  return;
}

// Chargement de la prescription demandée
$prescription = new CPrescriptionLabo();
$prescription->load($prescription_labo_id);
$prescription->loadRefs();

$patient = new CPatient();
$patient->load($patient_id);
$patient->loadRefsPrescriptions(PERM_EDIT);
foreach ($patient->_ref_prescriptions as $_prescription) {
  $_prescription->loadRefs();
}


// Création du template
$smarty = new CSmartyDP();

$smarty->assign("patient"     , $patient     );
$smarty->assign("prescription", $prescription);

$smarty->display("inc_vw_prescriptions");

