<?php
/**
 * @package Hospilibre\Hospi
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CCanDo;
use Ox\Core\CSmartyDP;
use Ox\Core\CValue;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Hospi\CChambre;
use Hospilibre\Hospi\CLit;

CCanDo::checkAdmin();

$group      = CGroups::loadCurrent();
$chambre_id = CValue::getOrSession("chambre_id");
$lit_id     = CValue::getOrSession("lit_id");

// Récupération de la chambre Î° ajouter/editer
$chambre = new CChambre();
$chambre->load($chambre_id);
$chambre->loadRefsNotes();
$chambre->loadRefService();
/** @var CChambre[] $chambres */
$chambres = $chambre->loadRefsLits(true);
foreach ($chambres as $_chambre) {
  $_chambre->loadRefsNotes();
}

if (!$chambre->_id) {
  CValue::setSession("lit_id", 0);
}

// Chargement du lit Î° ajouter/editer
$lit = new CLit();
$lit->load($lit_id);
$lit->loadRefChambre();

// Création du template
$smarty = new CSmartyDP();
$smarty->assign("_lit", $lit);
$smarty->assign("tag_lit", CLit::getTagLit($group->_id));
$smarty->assign("chambre", $chambre);
$smarty->assign("tag_chambre", CChambre::getTagChambre($group->_id));
$smarty->display("inc_vw_lit_line.tpl");