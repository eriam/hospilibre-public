<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Import\Framework\Spec;

use Ox\Core\Specification\AndX;
use Ox\Core\Specification\Enum;
use Ox\Core\Specification\NotNull;
use Ox\Core\Specification\SpecificationInterface;
use Hospilibre\ObservationResult\CObservationAbnormalFlag;

/**
 * External observation normal flag spec builder
 */
class ObservationAbnormalFlagSpecBuilder
{
    private const FIELD_ID                 = 'external_id';
    private const FIELD_OBSERVATION_RESULT = 'observation_result';
    private const FIELD_FLAG               = 'flag';

    public function build(): SpecificationInterface
    {
        return new AndX(
            ...[
                NotNull::is(
                    self::FIELD_ID,
                    self::FIELD_OBSERVATION_RESULT,
                    self::FIELD_FLAG
                ),
                Enum::is(self::FIELD_FLAG, CObservationAbnormalFlag::$flags)
            ]
        );
    }
}
