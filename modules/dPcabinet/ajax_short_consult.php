<?php
/**
 * @package Hospilibre\Cabinet
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CAppUI;
use Ox\Core\CCanDo;
use Ox\Core\CMbDT;
use Ox\Core\Module\CModule;
use Ox\Core\CSmartyDP;
use Ox\Core\CStoredObject;
use Ox\Core\CView;
use Hospilibre\Admin\CAccessMedicalData;
use Hospilibre\Cabinet\CActeNGAP;
use Hospilibre\Cabinet\CConsultation;
use Hospilibre\Cabinet\CExamComp;
use Hospilibre\Cabinet\CTechniqueComp;
use Hospilibre\Ccam\CDentCCAM;
use Hospilibre\Hospi\CService;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Patients\CAntecedent;
use Hospilibre\Patients\CTraitement;
use Hospilibre\PlanningOp\CModeSortieSejour;
use Hospilibre\PlanningOp\CSejour;
use Hospilibre\PlanningOp\CTypeAnesth;
use Hospilibre\Mpm\CPrescriptionLineMedicament;
use Hospilibre\Prescription\CPrescription;

CCanDo::checkRead();

$consult_id = CView::get("consult_id", "ref class|CConsultation");
$sejour_id  = CView::get("sejour_id", "ref class|CSejour");
$area_focus = CView::get("area_focus", "str");

CView::checkin();

$consult = new CConsultation();
$consult->load($consult_id);

CAccessMedicalData::logAccess($consult);

// Observation médicale créée mais son id n'est pas transmis
if (!$consult->_id) {
  $sejour = new CSejour();
  $sejour->load($sejour_id);

  CAccessMedicalData::logAccess($sejour);

  $consult = $sejour->loadRefObsEntree();
}

if ($consult->_id) {
  $consult->canDo();
  $patient = $consult->loadRefPatient();

  $consult_anesth = $consult->loadRefConsultAnesth();

  $consult->countActes();
  $consult->loadRefsActesNGAP();
  $consult->loadExtCodesCCAM();
  $consult->getAssociationCodesActes();
  $consult->loadPossibleActes();
  $consult->_ref_chir->loadRefFunction();
  $consult->loadListEtatsDents();

  // Chargement des rÎ¸gles de codage
  $consult->loadRefsCodagesCCAM();
  foreach ($consult->_ref_codages_ccam as $_codages) {
    $praticiens = CStoredObject::massLoadFwdRef($_codages, "praticien_id");
    CStoredObject::massLoadFwdRef($praticiens, "function_id");
    foreach ($_codages as $_codage) {
      $_codage->loadPraticien()->loadRefFunction();
      $_codage->loadActesCCAM();
      $_codage->getTarifTotal();
      foreach ($_codage->_ref_actes_ccam as $_acte) {
        $_acte->getTarif();
      }
    }
  }

  $dossier_medical = $patient->_ref_dossier_medical;

  if ($dossier_medical->_id) {
    $dossier_medical->canDo();
  }

  $user = CMediusers::get();
  $user->isAnesth();
  $user->isPraticien();
  $user->canDo();

  // Chargement des listes de praticiens
  $user = new CMediusers();
  $listAnesths = $user->loadAnesthesistes(PERM_DENY);
  $listChirs = $user->loadPraticiens(PERM_DENY);

  // Liste des dents CCAM
  $dents = CDentCCAM::loadList();
  $liste_dents = reset($dents);

  // Chargement des boxes
  $services = array();
  $list_mode_sortie = array();

  $sejour = $consult->loadRefSejour();

  // Chargement du sejour
  if ($sejour && $sejour->_id) {
    $sejour->loadExtDiagnostics();
    $sejour->loadRefDossierMedical();
    $sejour->loadNDA();

    // Cas des urgences
    $rpu = $sejour->loadRefRPU();
    if ($rpu && $rpu->_id) {
      $rpu->loadRefSejourMutation();
      $sejour->loadRefCurrAffectation()->loadRefService();

      // Urgences pour un séjour "urg"
      if (in_array($sejour->type, CSejour::getTypesSejoursUrgence($sejour->praticien_id))) {
        $services = CService::loadServicesUrgence();
      }

      if ($sejour->_ref_curr_affectation->_ref_service->radiologie == "1") {
        $services = array_merge($services, CService::loadServicesImagerie());
      }

      // UHCD pour un séjour "comp" et en UHCD
      if ($sejour->type == "comp" && $sejour->UHCD) {
        $services = CService::loadServicesUHCD();
      }

      if (CAppUI::conf("dPplanningOp CSejour use_custom_mode_sortie")) {
        $mode_sortie = new CModeSortieSejour();
        $where = array(
          "actif" => "= '1'",
        );
        $list_mode_sortie = $mode_sortie->loadGroupList($where);
      }
    }
  }
}

$smarty = new CSmartyDP();

$smarty->assign("consult", $consult);

if ($consult->_id) {
  $smarty->assign("listAnesths", $listAnesths);
  $smarty->assign("listChirs", $listChirs);
  $smarty->assign("services", $services);
  $smarty->assign("list_mode_sortie", $list_mode_sortie);
  $smarty->assign("consult_anesth", $consult_anesth);
  $smarty->assign("patient", $patient);
  $smarty->assign("_is_anesth", $user->isAnesth());
  $smarty->assign("antecedent", new CAntecedent());
  $smarty->assign("traitement", new CTraitement);
  $smarty->assign("liste_dents", $liste_dents);
  $smarty->assign("area_focus", $area_focus);
  if (CModule::getActive("dPprescription") && CPrescription::isMPMActive()) {
    $smarty->assign("line", new CPrescriptionLineMedicament());
  }
  $smarty->assign("userSel", $user);
  $smarty->assign("user", $user);
  $smarty->assign("sejour_id", $sejour_id);
  $smarty->assign("today", CMbDT::date());
  $smarty->assign("isPrescriptionInstalled", CModule::getActive("dPprescription"));
  $smarty->assign("acte_ngap", CActeNGAP::createEmptyFor($consult));
  /* Verification de l'existance de la base DRC (utilisée dans les antécédents */
  $smarty->assign('drc', array_key_exists('drc', CAppUI::conf('db')));
  $smarty->assign('cisp', array_key_exists('cisp', CAppUI::conf('db')));

  if ($consult_anesth->_id) {
    $consult_anesth->loadRefOperation();
    $consult_anesth->loadRefsTechniques();
    $consult_anesth->loadRefScoreLee();
    $consult_anesth->loadRefScoreMet();
    $anesth = new CTypeAnesth();
    $anesth = $anesth->loadGroupList();

    $smarty->assign("mins", range(0, 15 - 1, 1));
    $smarty->assign("secs", range(0, 60 - 1, 1));
    $smarty->assign("examComp", new CExamComp());
    $smarty->assign("techniquesComp", new CTechniqueComp());
    $smarty->assign("anesth", $anesth);
    $smarty->assign("view_prescription", 0);

    if (CAppUI::gconf("dPcabinet CConsultAnesth show_facteurs_risque")) {
      $sejour = new CSejour();
      $sejour->load($sejour_id);
      $sejour->loadRefDossierMedical();
      $smarty->assign("sejour", $sejour);
    }
  }
}

$smarty->display("inc_short_consult.tpl");
