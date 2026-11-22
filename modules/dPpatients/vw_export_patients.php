<?php
/**
 * @package Hospilibre\Patients
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CCanDo;
use Ox\Core\CSmartyDP;
use Ox\Core\CView;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Mediusers\CMediusers;

CCanDo::checkAdmin();

$praticien_id         = CView::post("praticien_id", "str", true);
$step                 = CView::post("step", "num default|100", true);
$start                = CView::post("start", "num default|0", true);
$directory            = CView::post("directory", "str", true);
$directory_name            = CView::post("directory_name", "str", true);
$all_prats            = CView::post("all_prats", "str", true);
$ignore_files         = CView::post("ignore_files", "str", true);
$generate_pdfpreviews = CView::post("generate_pdfpreviews", "str", true);
$date_min             = CView::post("date_min", "date", true);
$date_max             = CView::post("date_max", "date", true);
$patient_id           = CView::post("patient_id", "ref class|CPatient", true);
$ignore_consult_tag   = CView::post("ignore_consult_tag", "str", true);
$patient_infos        = CView::post("patient_infos", "str", true);
$update               = CView::post("update", "str", true);

CView::checkin();

$praticien = new CMediusers();
// load all the users from the group
$praticiens = $praticien->loadListFromType(null, PERM_READ, null, null, false);

$smarty = new CSmartyDP();
$smarty->assign("praticiens", $praticiens);
$smarty->assign("group", CGroups::loadCurrent());
$smarty->assign("praticien_id", $praticien_id);
$smarty->assign("array_praticien_id", is_array($praticien_id) ? $praticien_id : (($praticien_id) ? explode(',', $praticien_id) : []));
$smarty->assign("all_prats", $all_prats);
$smarty->assign("step", $step);
$smarty->assign("start", $start);
$smarty->assign("directory", $directory);
$smarty->assign("directory_name", $directory_name);
$smarty->assign("ignore_files", $ignore_files);
$smarty->assign("generate_pdfpreviews", $generate_pdfpreviews);
$smarty->assign("date_min", $date_min);
$smarty->assign("date_max", $date_max);
$smarty->assign("patient_id", $patient_id);
$smarty->assign("ignore_consult_tag", $ignore_consult_tag);
$smarty->assign("patient_infos", $patient_infos);
$smarty->assign("update", $update);
$smarty->display("vw_export_patients.tpl");
