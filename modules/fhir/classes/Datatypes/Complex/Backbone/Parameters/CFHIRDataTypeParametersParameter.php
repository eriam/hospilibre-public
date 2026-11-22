<?php
/**
 * @package Hospilibre\Fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Datatypes\Complex\Backbone\Parameters;

use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\CFHIRDataTypeBackboneElement;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeChoice;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeResource;

/**
 * FHIR data type
 */
class CFHIRDataTypeParametersParameter extends CFHIRDataTypeBackboneElement
{
    /** @var string */
    public const NAME = 'Parameters.parameter';

    /** @var CFHIRDataTypeString */
    public $name;

    /** @var CFHIRDataTypeChoice */
    public $value;

    /** @var CFHIRDataTypeResource */
    public $resource;

    /** @var CFHIRDataTypeReference[] */
    public $part;
}
