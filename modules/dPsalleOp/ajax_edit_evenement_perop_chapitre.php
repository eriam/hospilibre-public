<?php
/**
 * @package Hospilibre\SalleOp
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CCanDo;
use Ox\Core\CSmartyDP;
use Ox\Core\CView;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\SalleOp\CAnesthPeropCategorie;
use Hospilibre\SalleOp\CAnesthPeropChapitre;

CCanDo::checkEdit();
$chapitre_id = CView::get("chapitre_id", "ref class|CAnesthPeropChapitre");
CView::checkin();

$group = CGroups::loadCurrent();

$evenement_chapitre = new CAnesthPeropChapitre();
$evenement_chapitre->load($chapitre_id);
$evenement_chapitre->loadRefsCategoriesGestes();

// Select current group for a new object
if (!$evenement_chapitre->_id) {
  $evenement_chapitre->group_id = $group->_id;
}

// Création du template
$smarty = new CSmartyDP();
$smarty->assign("evenement_chapitre", $evenement_chapitre);
$smarty->assign("categorie_perop"   , new CAnesthPeropCategorie());
$smarty->assign("group"             , $group);
$smarty->display("inc_edit_evenement_perop_chapitre");
