<?php
/**
 * @package Hospilibre\Ssr
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Ssr\Generators;

use Ox\Core\CAppUI;
use Ox\Core\Generators\CObjectGenerator;
use Hospilibre\Ssr\CEquipement;
use Hospilibre\Ssr\CPlateauTechnique;

/**
 * @deprecated Will be replaced by fixtures
 *
 * Générateur d'équipement pour un plateau technique
 */
class CEquipementGenerator extends CObjectGenerator {
  static $mb_class = CEquipement::class;
  static $dependances = array(CPlateauTechnique::class);

  /** @var CEquipement */
  protected $object;

  /**
   * @inheritdoc
   */
  function generate() {
    $plateau_technique = (new CPlateauTechniqueGenerator())->generate();
    if ($this->force) {
      $obj = null;
    }
    else {
      $where = array(
        "plateau_id" => "= '$plateau_technique->_id'",
      );

      $obj = $this->getRandomObject($this->getMaxCount(), $where);
    }

    if ($obj && $obj->_id) {
      $this->object = $obj;
      $this->trace(static::TRACE_LOAD);
    }
    else {
      $this->object->plateau_id = $plateau_technique->_id;
      $this->object->nom = "Velo";

      if ($msg = $this->object->store()) {
        CAppUI::setMsg($msg, UI_MSG_WARNING);
      }
      else {
        CAppUI::setMsg("CEquipement-msg-create", UI_MSG_OK);
      }
    }

    return $this->object;
  }
}
