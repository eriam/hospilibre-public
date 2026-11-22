<?php

/**
 * @package Hospilibre\Fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Datatypes\Complex;

use Exception;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * FHIR human name data type
 */
class CFHIRDataTypeHumanName extends CFHIRDataTypeComplex
{
    /** @var string */
    public const NAME = 'HumanName';

    /** @var CFHIRDataTypeCode */
    public $use;

    /** @var CFHIRDataTypeString */
    public $text;

    /** @var CFHIRDataTypeString */
    public $family;

    /** @var CFHIRDataTypeString[] */
    public $given;

    /** @var CFHIRDataTypeString[] */
    public $prefix;

    /** @var CFHIRDataTypeString[] */
    public $suffix;

    /** @var CFHIRDataTypePeriod */
    public $period;

    /**
     * @param string          $family
     * @param string[]|string $given
     * @param string|null     $use
     * @param string|null     $text
     * @param array           $reference
     *
     * @return CFHIRDataTypeHumanName[]
     * @throws Exception|InvalidArgumentException
     */
    public static function addName(
        string $family,
        $given,
        ?string $use = null,
        ?string $text = null,
        array $reference = []
    ): array {
        if (!is_array($given)) {
            $given = [$given];
        }

        $formatted_given = array_map(
            function ($give) {
                return new CFHIRDataTypeString($give);
            },
            array_filter($given)
        );

        return array_merge(
            $reference,
            [
                self::build(
                    [
                        "use"    => $use ? new CFHIRDataTypeCode($use) : null,
                        "text"   => $text ? new CFHIRDataTypeString($text) : null,
                        "family" => new CFHIRDataTypeString($family),
                        "given"  => $formatted_given,
                    ]
                ),
            ]
        );
    }
}
