<?php
/**
 * @package Hospilibre\Patients
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Patients\Generators;

use Ox\Core\CAppUI;
use Ox\Core\Generators\CObjectGenerator;
use Hospilibre\Patients\CDossierMedical;
use Hospilibre\Patients\CPatient;
use Hospilibre\Populate\Generators\CPatientGenerator;

/**
 * @deprecated Will be replaced by fixtures
 *
 * Class CDossierMedicalGenerator
 *
 * @package Hospilibre\Populate
 */
class CDossierMedicalGenerator extends CObjectGenerator {
  static $mb_class = CDossierMedical::class;
  static $dependances = array(CPatient::class);

  /** @var CDossierMedical */
  protected $object;

  /**
   * @inheritdoc
   */
  public function generate(): CDossierMedical
  {
    $patient = (new CPatientGenerator())->generate();

    if ($this->force) {
      $obj = null;
    }
    else {
      $where = ["object_id" => "= '$patient->_id'", "object_class" => "= 'CPatient'"];
      $obj = $this->getRandomObject($this->getMaxCount(), $where);
    }

    if ($obj && $obj->_id) {
      $this->object = $obj;
      $this->trace(static::TRACE_LOAD);
    }
    else {
      $this->object->object_id    = $patient->_id;
      $this->object->object_class = $patient->_class;

      if ($msg = $this->object->store()) {
        CAppUI::setMsg($msg, UI_MSG_WARNING);
      } else {
        CAppUI::setMsg("CDossierMedical-msg-create", UI_MSG_OK);
      }
    }

    return $this->object;
  }
}
