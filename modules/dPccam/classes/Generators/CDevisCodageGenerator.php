<?php
/**
 * @package Hospilibre\Ccam
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Ccam\Generators;

use Ox\Core\CAppUI;
use Ox\Core\CMbDT;
use Ox\Core\Generators\CObjectGenerator;
use Hospilibre\Cabinet\CConsultation;
use Hospilibre\Populate\Generators\CConsultationGenerator;
use Hospilibre\Ccam\CDevisCodage;


/**
 * CDevisCodageGenerator
 */
class CDevisCodageGenerator extends CObjectGenerator {
  static $mb_class = CDevisCodage::class;
  static $dependances = [CConsultation::class];

    /** @var CDevisCodage */
  protected $object;

  /**
   * @inheritdoc
   */
  function generate(?string $objects_guid = null, ?string $codes_ccam = null) {
      $consultation = (new CConsultationGenerator())->generate();
      $praticien    = $consultation->loadRefPraticien();
      $patient      = $consultation->loadRefPatient();

    if ($this->force) {
      $obj = null;
    }
    else {
        $where = [
            "codable_class" => "= '$consultation->_class'",
            "codable_id"    => "= '$consultation->_id'",
        ];

        $obj = $this->getRandomObject($this->getMaxCount(), $where);
    }

    if ($obj && $obj->_id) {
      $this->object = $obj;
      $this->trace(static::TRACE_LOAD);
    }
    else {
        $this->object->praticien_id  = $praticien->_id;
        $this->object->codable_class = $consultation->_class;
        $this->object->codable_id    = $consultation->_id;
        $this->object->patient_id    = $patient->_id;
        $this->object->date          = CMbDT::date();
        $this->object->creation_date = "now";

      if ($msg = $this->object->store()) {
        CAppUI::setMsg($msg, UI_MSG_WARNING);
      }
      else {
        CAppUI::setMsg("CDevisCodage-msg-create", UI_MSG_OK);
      }
    }

    return $this->object;
  }
}
