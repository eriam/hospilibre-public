<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Import\Framework\Spec;

use Ox\Core\Specification\AndX;
use Ox\Core\Specification\NotNull;
use Ox\Core\Specification\SpecificationInterface;

/**
 * External observation result value spec builder
 */
class ObservationResultValueSpecBuilder
{
    private const FIELD_ID    = 'external_id';
    private const FIELD_VALUE = 'value';

    public function build(): SpecificationInterface
    {
        return new AndX(
            ...[
                   NotNull::is(
                       self::FIELD_ID,
                       self::FIELD_VALUE
                   ),
               ]
        );
    }
}
