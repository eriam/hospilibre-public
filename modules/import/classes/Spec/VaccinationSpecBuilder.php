<?php
/**
 * @package Hospilibre\Import
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Import\Framework\Spec;

use Ox\Core\Specification\AndX;
use Ox\Core\Specification\Enum;
use Ox\Core\Specification\IsNull;
use Ox\Core\Specification\NotNull;
use Ox\Core\Specification\OrX;
use Ox\Core\Specification\SpecificationInterface;
use Hospilibre\Cabinet\Vaccination\CVaccination;

/**
 * External vaccination spec builder
 */
class VaccinationSpecBuilder
{
    private const FIELD_ID        = 'external_id';
    private const FIELD_INJECTION = 'injection_id';
    private const FIELD_TYPE      = 'type';

    public function build(): SpecificationInterface
    {
        return new AndX(
            ...[
                   NotNull::is(self::FIELD_ID),
                   $this->getTypeSpec(),
               ]
        );
    }

    private function getTypeSpec(): SpecificationInterface
    {
        return new OrX(
            IsNull::is(self::FIELD_TYPE),
            Enum::is(self::FIELD_TYPE, CVaccination::TYPES_VACCINATIONS),
        );
    }
}
