<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Forms\Constraints;

use Ox\Core\CMbObject;
use Hospilibre\Forms\Exceptions\CouldNotEvaluateExpression;
use Hospilibre\System\Forms\CExClassConstraint;
use Hospilibre\System\Forms\CExClassMandatoryConstraint;

/**
 * Description
 */
interface ExClassConstraintOperatorInterface
{
    /**
     * Tell if given constraints are satisfied according to given object
     *
     * @param CMbObject                                          $object
     * @param CExClassConstraint[]|CExClassMandatoryConstraint[] $constraints
     *
     * @return bool
     * @throws CouldNotEvaluateExpression
     */
    public function checkConstraints(CMbObject $object, array $constraints): bool;
}
