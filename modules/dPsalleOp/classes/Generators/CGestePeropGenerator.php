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
use Hospilibre\Populate\Generators\CGroupsGenerator;
use Hospilibre\SalleOp\CGestePerop;

/**
 * @deprecated Will be replaced by fixtures
 *
 * CGestePeropGenerator
 */
class CGestePeropGenerator extends CObjectGenerator
{
    /** @var string $mb_class */
    public static $mb_class = CGestePerop::class;
    /** @var array $dependances */
    public static $dependances = [];

    /** @var CGestePerop */
    protected $object;

    /**
     * @inheritdoc
     */
    function generate()
    {
        $group    = (new CGroupsGenerator())->generate();

        if ($this->force) {
            $obj = null;
        } else {
            $where["group_id"] = "= '$group->_id'";

            $obj = $this->getRandomObject($this->getMaxCount(), $where);
        }

        if ($obj && $obj->_id) {
            $this->object = $obj;
            $this->trace(static::TRACE_LOAD);
        } else {
            $this->object->group_id    = $group->_id;
            $this->object->libelle     = "Test geste perop 1";
            $this->object->description = "Description test geste perop 1";

            if ($msg = $this->object->store()) {
                CAppUI::setMsg($msg, UI_MSG_WARNING);
            } else {
                CAppUI::setMsg("CDailyCheckList-msg-create", UI_MSG_OK);
            }
        }

        return $this->object;
    }
}
