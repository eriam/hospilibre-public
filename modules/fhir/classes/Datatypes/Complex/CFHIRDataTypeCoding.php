<?php
/**
 * @package Hospilibre\Fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Datatypes\Complex;

use Ox\Core\CMbArray;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeBoolean;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeUri;

/**
 * FHIR data type
 */
class CFHIRDataTypeCoding extends CFHIRDataTypeComplex
{
    /** @var string */
    public const NAME = 'Coding';

    /** @var CFHIRDataTypeUri */
    public $system;

    /** @var CFHIRDataTypeString */
    public $version;

    /** @var CFHIRDataTypeCode */
    public $code;

    /** @var CFHIRDataTypeString */
    public $display;

    /** @var CFHIRDataTypeBoolean */
    public $userSelected;

    /**
     * Get from values which come from values set
     *
     * @param array $values
     *
     * @return self
     */
    public static function fromValues(array $values): self
    {
        $self = new self();

        if ($code = CMbArray::get($values, 'code')) {
            $self->code = new CFHIRDataTypeCode($code);
        }

        if ($code_system = CMbArray::get($values, 'codeSystem')) {
            $self->system = new CFHIRDataTypeUri($code_system);
        }

        if ($display_name = CMbArray::get($values, 'displayName')) {
            $self->display = new CFHIRDataTypeString($display_name);
        }

        return $self;
    }

    /**
     * @param string     $system
     * @param string     $code
     * @param string     $displayName
     * @param array|null $reference
     *
     * @return CFHIRDataTypeCoding[] | CFHIRDataTypeCoding
     */
    public static function addCoding(string $system, string $code, string $displayName, ?array $reference = null)
    {
        $data = [
            "system"  => new CFHIRDataTypeString($system),
            "code"    => new CFHIRDataTypeCode($code),
            "display" => new CFHIRDataTypeString($displayName),
        ];

        if ($reference === null) {
            return CFHIRDataTypeCoding::build($data);
        }

        return array_merge($reference, [CFHIRDataTypeCoding::build($data)]);
    }
}
