<?php
/**
 * @package Hospilibre\Populate
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Populate\Generators;

use Ox\Core\CAppUI;
use Ox\Core\Generators\CObjectGenerator;
use Hospilibre\Hospi\CAffectation;
use Hospilibre\Hospi\CLit;
use Hospilibre\Hospi\CService;
use Hospilibre\PlanningOp\CSejour;

/**
 * Description
 */
class CAffectationGenerator extends CObjectGenerator {
  static $mb_class = CAffectation::class;
  static $dependances = array(CService::class);
  static $ds = array();

  public static $mode_entrees = ["N", "8", "7", "6", "0"];
  public static $provenance = ["1", "2", "3", "4", "5", "6", "7", "8", "R"];
  public static $mode_sorties = ["0", "4", "5", "6", "7", "8", "9"];

  /** @var CAffectation */
  protected $object;

  /** @var CSejour */
  protected $sejour;
  /** @var CService */
  protected $service;
  /** @var CLit */
  protected $lit;
  /** @var string */
  protected $entree;

  /**
   * Injecte le séjour à transférer (obligatoire : pas de génération détachée)
   *
   * @param CSejour $s Séjour
   *
   * @return $this
   */
  public function setSejour(CSejour $s) {
    if ($s->_id) {
      $this->sejour = $s;
    }
    return $this;
  }

  /**
   * Injecte le service cible du transfert
   *
   * @param CService $s Service
   *
   * @return $this
   */
  public function setService(CService $s) {
    if ($s->_id) {
      $this->service = $s;
    }
    return $this;
  }

  /**
   * Injecte le lit cible du transfert
   *
   * @param CLit $l Lit
   *
   * @return $this
   */
  public function setLit(CLit $l) {
    if ($l->_id) {
      $this->lit = $l;
    }
    return $this;
  }

  /**
   * Injecte la date/heure d'entrée dans la nouvelle affectation (moment du transfert)
   *
   * @param string $d Date/heure d'entrée
   *
   * @return $this
   */
  public function setEntree(string $d) {
    $this->entree = $d;
    return $this;
  }

  /**
   * @inheritdoc
   */
  function generate() {
    if (!$this->sejour || !$this->sejour->_id) {
      throw new \RuntimeException('CAffectationGenerator : séjour à injecter via setSejour()');
    }
    if (!$this->service || !$this->service->_id || !$this->lit || !$this->lit->_id) {
      throw new \RuntimeException('CAffectationGenerator : service et lit à injecter');
    }
    $this->object->sejour_id    = $this->sejour->_id;
    $this->object->service_id   = $this->service->_id;
    $this->object->lit_id       = $this->lit->_id;
    $this->object->praticien_id = $this->sejour->praticien_id;
    $this->object->entree       = $this->entree ?: $this->sejour->entree_reelle;
    // CAffectation.sortie est notNull (moreThan|entree) : on la pose à la sortie
    // prévue du séjour (affectation courante après transfert, raccourcie plus tard).
    $this->object->sortie       = $this->sejour->sortie_prevue;
    $this->object->mode_entree  = self::$mode_entrees[array_rand(self::$mode_entrees)];
    $this->object->provenance   = self::$provenance[array_rand(self::$provenance)];

    if ($msg = $this->object->store()) {
      CAppUI::setMsg($msg, UI_MSG_WARNING);
      return null;
    }
    CAppUI::setMsg("CAffectation-msg-create", UI_MSG_OK);
    $this->trace(static::TRACE_STORE);

    return $this->object;
  }
}
