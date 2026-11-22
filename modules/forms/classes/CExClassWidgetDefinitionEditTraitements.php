<?php
/**
 * @package Hospilibre\Forms
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Forms;

use Ox\Core\Module\CModule;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Patients\CPatient;
use Hospilibre\Patients\CTraitement;
use Hospilibre\Mpm\CPrescriptionLineMedicament;
use Hospilibre\Prescription\CPrescription;
use Hospilibre\System\Forms\CExObject;

/**
 * Description
 */
class CExClassWidgetDefinitionEditTraitements extends CExClassWidgetDefinition {
  public $name = "EditTraitements";

  public $template_name = "inc_edit_traitements";

  public $default_dimensions = array(
    "width"  => 300,
    "height" => 200,
  );

  /**
   * @inheritdoc
   */
  function prepareTemplate(CExObject $ex_object, $mode = "normal") {
    $tpl = $this->template;

    $isPrescriptionInstalled = CModule::getActive("dPprescription") && CPrescription::isMPMActive();

    if ($isPrescriptionInstalled) {
      $tpl->assign("line", new CPrescriptionLineMedicament());
    }

    $user = CMediusers::get();

    $sejour  = $ex_object->getReferenceObject("CSejour");
    $patient = $ex_object->getReferenceObject("CPatient") ?: new CPatient();

    $tpl->assign("traitement", new CTraitement());
    $tpl->assign("patient", $patient);
    $tpl->assign("_is_anesth", $user->isAnesth());
    $tpl->assign("userSel", $user);
    $tpl->assign("sejour_id", $sejour ? $sejour->_id : null);
    $tpl->assign("addform", mt_rand());
  }
}
