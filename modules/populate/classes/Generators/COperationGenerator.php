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
use Ox\Core\CMbDT;
use Ox\Core\CRequest;
use Ox\Core\CSQLDataSource;
use Ox\Core\Generators\CObjectGenerator;
use Hospilibre\Bloc\CSalle;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\PlanningOp\COperation;
use Hospilibre\PlanningOp\CSejour;

/**
 * Description
 */
class COperationGenerator extends CObjectGenerator {
  const TWO_HOURS = 7200;
  const FIFTEEN_MIN = 900;

  static $mb_class = COperation::class;
  static $dependances = array(CMediusers::class, CSejour::class);
  static $ds = array(
    "ccamV2" => array("codes_ccam", "examen")
  );

  /** @var CSejour */
  protected $sejour;
  /** @var COperation */
  protected $object;

  /** @var CSalle */
  protected $salle;

  /** @var CMediusers */
  protected $praticien;
  /** @var string */
  protected $date;

  /**
   * @inheritdoc
   * @throws Exception
   */
  function generate() {
    if (!$this->sejour || !$this->sejour->_id) {
      throw new \RuntimeException('COperationGenerator : séjour à injecter via init()');
    }

    $this->object->sejour_id = $this->sejour->_id;

    $praticien              = $this->praticien ?: (new CMediusersGenerator())->setGroupId($this->sejour->group_id)->generate();
    $this->object->chir_id  = $praticien->_id;
    $this->object->date     = $this->date ?: CMbDT::getRandomDate($this->sejour->entree, $this->sejour->sortie, "Y-m-d");

    $codes                     = $this->getRandomCCAMCodes(2);
    $this->object->codes_ccam     = implode("|", array_keys($codes));
    $this->object->examen         = implode("\n", $codes);
    $this->object->temp_operation = CMbDT::getRandomDate(static::FIFTEEN_MIN, static::TWO_HOURS, "H:i:s");

    if (!$this->salle) {
      throw new \RuntimeException('COperationGenerator : salle à injecter via setSalle()');
    }
    $this->object->salle_id = $this->salle->_id;

    if ($msg = $this->object->store()) {
      CAppUI::setMsg($msg, UI_MSG_WARNING);
    }
    else {
      CAppUI::setMsg("COperation-msg-create", UI_MSG_OK);
      $this->trace(static::TRACE_STORE);
    }

    return $this->object;
  }

  /**
   * Init the generator
   *
   * @param CSejour $sejour Sejour to link operation to
   *
   * @return static
   */
  function init($sejour) {
    $this->sejour = $sejour;

    return $this;
  }

  /**
   * Get random CCAM codes
   *
   * @param int $count Count of codes to return
   *
   * @return array
   */
  protected function getRandomCCAMCodes($count = 2) {
    $ds = CSQLDataSource::get('ccamV2');

    $query = new CRequest();
    $query->addTable('p_acte');
    try {
      $total = (int) $ds->loadResult($query->makeSelectCount());
    }
    catch (Exception $e) {
      CAppUI::setMsg($e->getMessage(), UI_MSG_WARNING);

      return array();
    }


    $codes = array();

    for ($i = 0; $i < $count; $i++) {
      $limit = rand(0, $total - 1);
      $query = new CRequest();
      $query->addSelect(array("CODE", "LIBELLELONG"));
      $query->addTable('p_acte');
      $query->setLimit("$limit, 1");

      try {
        $code = $ds->loadHash($query->makeSelect());
      }
      catch (Exception $e) {
        CAppUI::setMsg($e->getMessage(), UI_MSG_WARNING);
        continue;
      }

      $codes[$code['CODE']] = $code['LIBELLELONG'];
    }

    return $codes;
  }

  /**
   * @param CSalle $salle
   *
   * @return $this
   */
  public function setSalle(CSalle $salle): self {
    $this->salle = $salle;
    return $this;
  }

  /**
   * @param CMediusers $user
   *
   * @return $this
   */
  public function setPraticien(CMediusers $user): self {
    if ($user->_id) {
      $this->praticien = $user;
    }
    return $this;
  }

  /**
   * @param string $date
   *
   * @return $this
   */
  public function setDate(string $date): self {
    $this->date = $date;
    return $this;
  }
}
