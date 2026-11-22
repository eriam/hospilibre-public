<?php
/**
 * @package Hospilibre\Populate
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Populate\Generators;

use Exception;
use Ox\Core\CAppUI;
use Ox\Core\Generators\CObjectGenerator;
use Hospilibre\Hospi\CAffectation;
use Hospilibre\Hospi\CLit;
use Hospilibre\Mediusers\CFunctions;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Patients\CPatient;
use Hospilibre\PlanningOp\CSejour;

/**
 * Class CSejourGenerator.
 * Generate a CSejour object
 */
class CSejourGenerator extends CObjectGenerator {
  /** @var string  */
  static $mb_class    = CSejour::class;

  /** @var string[]  */
  static $dependances = [CMediusers::class, CFunctions::class, CPatient::class];

  /** @var array The possible types of CSejour */
  public static $types = ['random', 'ambu', 'comp'];

  /** @var CSejour */
  protected $object;

  /** @var CPatient */
  protected $patient;
  /** @var CMediusers */
  protected $praticien;
  /** @var CLit */
  protected $lit;
  /** @var string */
  protected $entree;
  /** @var string */
  protected $sortie;

  /**
   * Injecte le patient du séjour (sinon un patient est généré via CPatientGenerator)
   *
   * @param CPatient $p Patient
   *
   * @return $this
   */
  public function setPatient(CPatient $p): self {
    if ($p->_id) {
      $this->patient = $p;
    }
    return $this;
  }

  /**
   * Injecte le praticien du séjour (sinon un praticien est généré via CMediusersGenerator)
   *
   * @param CMediusers $u Praticien
   *
   * @return $this
   */
  public function setPraticien(CMediusers $u): self {
    if ($u->_id) {
      $this->praticien = $u;
    }
    return $this;
  }

  /**
   * Injecte le lit d'affectation du séjour ; le service est dérivé de
   * `$l->_ref_chambre->service_id` (chambre préchargée par l'appelant)
   *
   * @param CLit $l Lit
   *
   * @return $this
   */
  public function setLit(CLit $l): self {
    if ($l->_id) {
      $this->lit = $l;
    }
    return $this;
  }

  /**
   * Injecte les dates du séjour (entrée réelle du jour, sortie prévue future)
   *
   * @param string $entree Date/heure d'entrée
   * @param string $sortie Date/heure de sortie prévue
   *
   * @return $this
   */
  public function setDates(string $entree, string $sortie): self {
    $this->entree = $entree;
    $this->sortie = $sortie;
    return $this;
  }

  /**
   * Generate a CSejour
   *
   * @return CSejour
   * @throws Exception
   */
  function generate() {
    $praticien                  = $this->praticien ?: (new CMediusersGenerator())->generate();
    $this->object->praticien_id = $praticien->_id;

    $function               = $praticien->loadRefFunction();
    $this->object->group_id = $function->group_id;

    $patient                  = $this->patient ?: (new CPatientGenerator())->generate();
    $this->object->patient_id = $patient->_id;

    $codes               = $this->getRandomCIM10Code();
    $this->object->DP    = $codes['code'];
    $this->object->rques = $codes['code'] . ' : ' . $codes['libelle'];

    $this->object->type          = $this->type ?: 'comp';
    $this->object->entree_prevue = $this->entree;
    $this->object->entree_reelle = $this->entree; // admission le jour J (séjour en cours)
    $this->object->sortie_prevue = $this->sortie; // futur -> pas de sortie_reelle

    $lit = $this->lit;
    if (!$lit || !$lit->_id) {
      throw new \RuntimeException('CSejourGenerator : lit à injecter via setLit()');
    }
    $service_id                = $lit->_ref_chambre->service_id; // _ref_chambre préchargé par le moteur
    $this->object->service_id  = $service_id;

    if ($collisions = $this->object->getCollisions()) {
      $this->object = reset($collisions);
    }

    if ($msg = $this->object->store()) {
      CAppUI::stepAjax($msg, UI_MSG_WARNING);
      return null;
    }

    if ($this->force) {
      return $this->object;
    }

    CAppUI::setMsg("CSejour-msg-create", UI_MSG_OK);
    $this->trace(static::TRACE_STORE);

    $affectation             = new CAffectation();
    $affectation->service_id = $service_id;
    $affectation->lit_id     = $lit->_id;
    $affectation->sejour_id  = $this->object->_id;
    $affectation->entree     = $this->object->entree_reelle;
    $affectation->sortie     = $this->object->sortie_prevue; // notNull ; affectation courante

    if ($msg = $affectation->store()) {
      CAppUI::stepAjax($msg, UI_MSG_WARNING);
    }

    (new CActeNGAPGenerator())
      ->setGroupId($this->object->group_id)
      ->setTargetObject($this->object)
      ->setExecutant($praticien)->generate();

    return $this->object;
  }

  /**
   * Tire un pseudo-code CIM10 pour peupler le diagnostic principal (DP) du séjour.
   *
   * Divergence de portage : la base réimplémentée (CObjectGenerator) n'expose pas de
   * getRandomCIM10Code() (contrairement au socle OXOL d'origine, qui piochait dans une
   * vraie table de référence CIM10 via un datasource dédié `cim10`, ni configuré ni
   * peuplé dans ce fork — cf. includes/config_overload.php, commenté). On reste
   * autonome, comme CPatientGenerator::getRandomCIM10Code() : courte liste fictive.
   *
   * @return array{code: string, libelle: string}
   */
  protected function getRandomCIM10Code() {
    $codes = [
      'I10' => 'Hypertension essentielle (primitive)',
      'E11' => 'Diabète sucré non insulino-dépendant',
      'J45' => 'Asthme',
      'M54' => 'Dorsalgie',
      'K21' => 'Reflux gastro-oesophagien',
      'F32' => 'Episode dépressif',
      'J06' => 'Infection aiguë des voies respiratoires supérieures',
      'N39' => "Autres affections de l'appareil urinaire",
    ];

    $code = array_rand($codes);

    return ['code' => $code, 'libelle' => $codes[$code]];
  }
}
