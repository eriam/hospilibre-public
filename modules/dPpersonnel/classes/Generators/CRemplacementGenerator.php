<?php
/**
 * @package Hospilibre\Personnel
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Personnel\Generators;

use Ox\Core\CMbDT;
use Ox\Core\CAppUI;
use Ox\Core\Generators\CObjectGenerator;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Populate\Generators\CMediusersGenerator;
use Hospilibre\Personnel\CRemplacement;

/**
 * @deprecated Will be replaced by fixtures
 */
class CRemplacementGenerator extends CObjectGenerator {
  static $mb_class = CRemplacement::class;
  static $dependances = array(CMediusers::class);
  static $ds = array();

  /** @var CRemplacement */
  protected $object;

  /**
   * @inheritdoc
   */
  function generate() {
    $remplace   = (new CMediusersGenerator())->generate();
    $remplacant = (new CMediusersGenerator())->generate();

    if ($this->force) {
      $obj = null;
    }
    else {
      $where = array(
        "remplace_id"   => "= '$remplace->_id'",
        "remplacant_id" => "= '$remplacant->_id'",
      );

      $obj = $this->getRandomObject($this->getMaxCount(), $where);
    }

    if ($obj && $obj->_id) {
      $this->object = $obj;
      $this->trace(static::TRACE_LOAD);
    }
    else {
      $number                         = random_int(1, 1000);
      $this->object->debut         = CMbDT::dateTime("01:00:00");
      $this->object->fin           = CMbDT::dateTime("11:00:00");
      $this->object->remplace_id   = $remplace->_id;
      $this->object->remplacant_id = $remplacant->_id;
      $this->object->libelle       = "Remplacement nÂ° " . $number;

      if ($msg = $this->object->store()) {
        CAppUI::setMsg($msg, UI_MSG_WARNING);
      }
      else {
        CAppUI::setMsg("CRemplacement-msg-create", UI_MSG_OK);
      }
    }

    return $this->object;
  }
}
