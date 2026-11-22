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
 * External observation value unit spec builder
 */
class ObservationValueUnitSpecBuilder
{
    private const FIELD_ID            = 'external_id';
    private const FIELD_CODE          = 'code';
    private const FIELD_LABEL         = 'label';
    private const FIELD_CODING_SYSTEM = 'coding_system';

    public function build(): SpecificationInterface
    {
        return new AndX(
            ...[
                   NotNull::is(
                       self::FIELD_ID,
                       self::FIELD_CODE,
                       self::FIELD_LABEL,
                       self::FIELD_CODING_SYSTEM
                   ),
               ]
        );
    }
}
