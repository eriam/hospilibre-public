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
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Populate\Generators\CMediusersGenerator;
use Hospilibre\Patients\CDossierMedical;
use Hospilibre\Patients\CEvenementPatient;

/**
 * @deprecated Will be replaced by fixtures
 *
 * Class CEvenementPatientGenerator
 *
 * @package Hospilibre\Populate
 */
class CEvenementPatientGenerator extends CObjectGenerator {
  static $mb_class = CEvenementPatient::class;
  static $dependances = array(CMediusers::class, CDossierMedical::class);

  /** @var CEvenementPatient */
  protected $object;

  /**
   * @inheritdoc
   */
  public function generate(): CEvenementPatient
  {
    $dossier_medical = (new CDossierMedicalGenerator())->generate();
    $owner= (new CMediusersGenerator())->generate();

    if ($this->force) {
      $obj = null;
    }
    else {
      $where = ["dossier_medical_id" => "= '$dossier_medical->_id'"];
      $obj = $this->getRandomObject($this->getMaxCount(), $where);
    }

    if ($obj && $obj->_id) {
      $this->object = $obj;
      $this->trace(static::TRACE_LOAD);
    }
    else {
      $this->object->creation_date  = "now";
      $this->object->date           = "now";
      $this->object->libelle        = "Evt Generated";
      $this->object->praticien_id   = $owner->_id;
      $this->object->owner_id       = $owner->_id;
      $this->object->dossier_medical_id = $dossier_medical->_id;

      if ($msg = $this->object->store()) {
        CAppUI::setMsg($msg, UI_MSG_WARNING);
      } else {
        CAppUI::setMsg("CEvenementPatient-msg-create", UI_MSG_OK);
      }
    }

    return $this->object;
  }
}
