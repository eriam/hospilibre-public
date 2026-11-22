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
use Ox\Core\CMbString;
use Ox\Core\Generators\CObjectGenerator;
use Hospilibre\Admin\CUser;
use Hospilibre\Mediusers\CFunctions;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Mediusers\CSpecCPAM;

/**
 * Description
 */
class CMediusersGenerator extends CObjectGenerator {
  static $mb_class = CMediusers::class;
  static $dependances = array(CFunctions::class);

  /** @var CMediusers */
  protected $object;

  /**
   * Generate a CMediusers
   *
   * @param string $type CMediusers type
   *
   * @return CMediusers
   * @throws Exception
   */
  function generate($type = 'Médecin', ?int $spec_cpam_id = null) {
    $type_id = array_keys(CUser::$types, $type);
    $type_id = reset($type_id);

    $function = (new CFunctionsGenerator())->setGroupId($this->group_id)->generate();

    if ($this->force) {
      $obj = null;
    }
    else {
      // CObjectGenerator::getRandomObject() (base réimplémentée) attend un
      // fragment SQL brut (?string), pas un tableau colonne => condition
      // (convention de l'ancien socle Mediboard) : on le construit ici.
      $where = "`user_id` IN (SELECT user_id FROM `users` WHERE user_type = '{$type_id}')"
        . " AND `function_id` = '$function->_id'";

      if ($spec_cpam_id) {
        $where .= " AND `spec_cpam_id` = {$spec_cpam_id}";
      }

      $obj = $this->getRandomObject($this->getMaxCount(), $where);
    }

    if ($obj) {
      $this->object = $obj;
      $this->trace(static::TRACE_LOAD);
    }
    else {
      $this->object->function_id = $function->_id;

      $persona = CPersonaSource::randomPrenom();

      $this->object->_user_first_name = $persona['prenom'];
      $this->object->_user_last_name  = CPersonaSource::randomNom();
      $this->object->_user_username   = mb_substr($this->object->_user_first_name, 0, 1) . $this->object->_user_last_name;
      $this->object->_user_sexe       = $persona['sexe'];

      $user                = new CUser();
      $user->user_username = $this->object->_user_username;
      if ($match = $user->loadMatchingObjectEsc()) {
        $this->object->_user_username .= uniqid('', true);
      }

      $this->object->_user_type = $type_id;

      $this->object->spec_cpam_id = $spec_cpam_id ? $spec_cpam_id : $this->getRandomSpecCPAM()->_id;
      $this->object->rpps         = $this->getRandomRPPS();
      $this->object->titres       = "Ancien interne en " . $this->getRandomSpecCPAM()->text;

      if ($msg = $this->object->store()) {
        CAppUI::stepAjax($msg, UI_MSG_WARNING);
        return null;
      }
      else {
        CAppUI::setMsg("CMediusers-msg-create", UI_MSG_OK);
        $this->trace(static::TRACE_STORE);
      }
    }


    return $this->object;
  }

  /**
   * Get a random RPPS number
   *
   * @return string
   */
  function getRandomRPPS() {
    $number = '';
    for ($i = 0; $i < 10; $i++) {
      $number .= rand(0, 9);
    }

    return CMbString::createLuhn($number);
  }

  /**
   * Get a random CPAM speciality
   *
   * @return CSpecCPAM
   */
  function getRandomSpecCPAM() {
    $list = CSpecCPAM::getList();
    shuffle($list);

    $spec_cpam = reset($list);

    return $spec_cpam;
  }
}
