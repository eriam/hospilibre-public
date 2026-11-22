<?php
/**
 * @package Hospilibre\Hl7
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CSmartyDP;
use Hospilibre\Patients\CPatient;

$patient = new CPatient();

//template
$smarty = new CSmartyDP();
$smarty->assign("patient", $patient);
$smarty->display("inc_vw_generator.tpl");