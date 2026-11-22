<?php
/**
 * @package Hospilibre\PlanningOp
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CCanDo;
use Ox\Core\CSmartyDP;
use Ox\Core\CView;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Patients\CPatient;

CCanDo::checkRead();

$patient_id = CView::get("patient_id", "ref class|CPatient");

CView::checkIn();

$group_id = CGroups::loadCurrent()->_id;

$patient = new CPatient();
$patient->load($patient_id);
$sejours = $patient->loadRefsSejours(array("sejour.group_id" => "= '$group_id'"));

// Liste des Etablissements selon Permissions
$etablissements = new CMediusers();
$etablissements = $etablissements->loadEtablissements(PERM_READ);

// Création du template
$smarty = new CSmartyDP();

$smarty->assign("sejours_collision", $patient->getSejoursCollisions());
$smarty->assign("sejours", $sejours);
$smarty->assign("etablissements", $etablissements);

$smarty->display("inc_get_sejours");
