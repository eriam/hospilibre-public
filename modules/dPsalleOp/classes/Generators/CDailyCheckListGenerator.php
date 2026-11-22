<?php
/**
 * @package Hospilibre\SalleOp
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\SalleOp\Generators;

use Ox\Core\CAppUI;
use Ox\Core\Generators\CObjectGenerator;
use Hospilibre\Bloc\CSalle;
use Hospilibre\Populate\Generators\CSalleGenerator;
use Hospilibre\SalleOp\CDailyCheckList;

/**
 * @deprecated Will be replaced by fixtures
 *
 * CDebiteurOXGenerator
 */
class CDailyCheckListGenerator extends CObjectGenerator {
  static $mb_class = CDailyCheckList::class;
  static $dependances = array(CSalle::class);

  /** @var CDailyCheckList */
  protected $object;

  /**
   * @inheritdoc
   */
  function generate() {
    $salle = (new CSalleGenerator())->generate();

    if ($this->force) {
      $obj = null;
    }
    else {
      $where = array(
        "object_id" => "= '$salle->_id'",
        "object_class" => "= '$salle->_class'",
      );

      $obj = $this->getRandomObject($this->getMaxCount(), $where);
    }

    if ($obj && $obj->_id) {
      $this->object = $obj;
      $this->trace(static::TRACE_LOAD);
    }
    else {
      $this->object->object_id = $salle->_id;
      $this->object->object_class = $salle->_class;
      $this->object->date = "now";

      if ($msg = $this->object->store()) {
        CAppUI::setMsg($msg, UI_MSG_WARNING);
      }
      else {
        CAppUI::setMsg("CDailyCheckList-msg-create", UI_MSG_OK);
      }
    }

    return $this->object;
  }
}
