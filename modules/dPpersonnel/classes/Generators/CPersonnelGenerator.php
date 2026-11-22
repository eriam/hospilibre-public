<?php

/**
 * @package Hospilibre\Personnel
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Personnel\Generators;

use Ox\Core\CAppUI;
use Ox\Core\Generators\CObjectGenerator;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Populate\Generators\CMediusersGenerator;
use Hospilibre\Personnel\CPersonnel;

/**
 * @deprecated Will be replaced by fixtures
 *
 * CPresonnel generator
 */
class CPersonnelGenerator extends CObjectGenerator
{
    /** @var string $mb_class */
    public static $mb_class = CPersonnel::class;
    /** @var array $dependances */
    public static $dependances = [CMediusers::class];

    /** @var CPersonnel */
    protected $object;

    /**
     * @inheritdoc
     */
    public function generate(): CPersonnel
    {
        // Propage le group_id du générateur de personnel au générateur de
        // mediuser interne : sans cela, ce dernier retombe sur l'établissement
        // courant par défaut (constructeur de CObjectGenerator) au lieu de
        // l'établissement demandé par l'appelant.
        // Type 'Personnel' (non médical) : un personnel ne doit pas être créé comme
        // Médecin (type par défaut de CMediusersGenerator), sinon il deviendrait
        // éligible comme praticien de séjour.
        $user = (new CMediusersGenerator())->setGroupId($this->group_id)->generate('Personnel');

        if ($this->force) {
            $obj = null;
        } else {
            // CObjectGenerator::getRandomObject() attend un fragment SQL brut
            // (?string), pas un tableau colonne => condition.
            $where = "`user_id` = '$user->_id'";

            $obj = $this->getRandomObject($this->getMaxCount(), $where);
        }

        if ($obj && $obj->_id) {
            $this->object = $obj;
            $this->trace(static::TRACE_LOAD);
        } else {
            $this->object->user_id     = $user->_id;
            $this->object->emplacement = "op";
            $this->object->actif       = 1;

            if ($msg = $this->object->store()) {
                CAppUI::setMsg($msg, UI_MSG_WARNING);
            } else {
                CAppUI::setMsg("CPersonnel-msg-create", UI_MSG_OK);
            }
        }

        return $this->object;
    }
}
