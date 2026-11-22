<?php
/**
 * @package Hospilibre\Urgences
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CSmartyDP;
use Ox\Core\CValue;
use Hospilibre\Admin\CAccessMedicalData;
use Hospilibre\PlanningOp\CSejour;

$sejour_id = CValue::get("sejour_id");

$sejour = new CSejour();
$sejour->load($sejour_id);

CAccessMedicalData::logAccess($sejour);

// Création du template
$smarty = new CSmartyDP("modules/dPccam");
$smarty->assign("object", $sejour);
$smarty->display("inc_frais_divers");
