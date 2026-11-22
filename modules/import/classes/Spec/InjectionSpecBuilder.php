<?php
/**
 * @package Hospilibre\Import
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Import\Framework\Spec;

use DateTime;
use Ox\Core\Specification\AndX;
use Ox\Core\Specification\InstanceOfX;
use Ox\Core\Specification\NotNull;
use Ox\Core\Specification\SpecificationInterface;

/**
 * External injection spec builder
 */
class InjectionSpecBuilder
{
    private const FIELD_ID             = 'external_id';
    private const FIELD_PATIENT        = 'patient_id';
    private const FIELD_INJECTION_DATE = 'injection_date';

    public function build(): SpecificationInterface
    {
        return new AndX(
            ...[
                   NotNull::is(self::FIELD_ID, self::FIELD_PATIENT, self::FIELD_INJECTION_DATE),
                   InstanceOfX::is(self::FIELD_INJECTION_DATE, DateTime::class)
               ]
        );
    }
}
