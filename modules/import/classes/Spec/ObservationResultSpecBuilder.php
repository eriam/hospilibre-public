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
use Hospilibre\ObservationResult\CObservationResult;

/**
 * External observation result spec builder
 */
class ObservationResultSpecBuilder
{
    private const FIELD_ID                     = 'external_id';
    private const FIELD_OBSERVATION_RESULT_SET = 'observation_result_set_id';
    private const FIELD_STATUS                 = 'status';

    public function build(): SpecificationInterface
    {
        return new AndX(
            ...[
                   NotNull::is(
                       self::FIELD_ID,
                       self::FIELD_OBSERVATION_RESULT_SET
                   ),
                   Enum::is(
                       self::FIELD_STATUS,
                       [
                           CObservationResult::STATUS_FINAL,
                           CObservationResult::STATUS_PRELIMINARY,
                           CObservationResult::STATUS_CANCELED,
                           CObservationResult::STATUS_CORRECTED,
                           CObservationResult::STATUS_DELETED,
                       ]
                   ),
               ]
        );
    }
}
