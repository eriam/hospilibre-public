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
use Ox\Core\Specification\Enum;
use Ox\Core\Specification\GreaterThan;
use Ox\Core\Specification\GreaterThanOrEqual;
use Ox\Core\Specification\InstanceOfX;
use Ox\Core\Specification\IsNull;
use Ox\Core\Specification\LessThanOrEqual;
use Ox\Core\Specification\Not;
use Ox\Core\Specification\NotNull;
use Ox\Core\Specification\OrX;
use Ox\Core\Specification\SpecificationInterface;

/**
 * Description
 */
class ConstanteSpecBuilder
{
    private const FIELD_ID = 'external_id';

    private const FIELD_USER_ID    = 'user_id';
    private const FIELD_PATIENT_ID = 'patient_id';
    private const FIELD_DATETIME   = 'datetime';
    private const FIELD_TAILLE     = 'taille';
    private const FIELD_POIDS      = 'poids';

    public function build(): ?SpecificationInterface
    {
        return new AndX(
            ...[
                   NotNull::is(self::FIELD_ID),
                   NotNull::is(self::FIELD_PATIENT_ID),
                   $this->buildDateTimeSpec(),
                   $this->buildTailleSpec(),
                   $this->buildPoidsSpec(),
               ]
        );
    }

    private function buildDateTimeSpec(): SpecificationInterface
    {
        return new OrX(
            ...[
                   IsNull::is(self::FIELD_DATETIME),
                   InstanceOfX::is(self::FIELD_DATETIME, DateTime::class),
               ]
        );
    }

    private function buildTailleSpec(): SpecificationInterface
    {
        return new OrX(
            IsNull::is(self::FIELD_TAILLE),
            new AndX(
                GreaterThanOrEqual::is(self::FIELD_TAILLE, 20),
                LessThanOrEqual::is(self::FIELD_TAILLE, 300),
            )
        );
    }

    private function buildPoidsSpec(): SpecificationInterface
    {
        return new OrX(
            IsNull::is(self::FIELD_POIDS),
            LessThanOrEqual::is(self::FIELD_POIDS, 500),
        );
    }
}
