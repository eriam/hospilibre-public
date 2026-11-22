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
use Hospilibre\SalleOp\CDailyCheckItemCategory;

/**
 * @deprecated Will be replaced by fixtures
 *
 * CDebiteurOXGenerator
 */
class CDailyCheckItemCategoryGenerator extends CObjectGenerator {
  static $mb_class = CDailyCheckItemCategory::class;
  static $dependances = array(CSalle::class);

  /** @var CDailyCheckItemCategory */
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
        "target_id" => "= '$salle->_id'",
        "target_class" => "= '$salle->_class'",
      );

      $obj = $this->getRandomObject($this->getMaxCount(), $where);
    }

    if ($obj && $obj->_id) {
      $this->object = $obj;
      $this->trace(static::TRACE_LOAD);
    }
    else {
      $this->object->target_id = $salle->_id;
      $this->object->target_class = $salle->_class;
      $this->object->title = "Catégorie 1";

      if ($msg = $this->object->store()) {
        CAppUI::setMsg($msg, UI_MSG_WARNING);
      }
      else {
        CAppUI::setMsg("CDailyCheckItemCategory-msg-create", UI_MSG_OK);
      }
    }

    return $this->object;
  }
}
