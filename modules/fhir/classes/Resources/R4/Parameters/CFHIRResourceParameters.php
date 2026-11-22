<?php

/**
 * @package Hospilibre\Fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Resources\R4\Parameters;

use Ox\Interop\Fhir\Contracts\Resources\ResourceParametersInterface;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Parameters\CFHIRDataTypeParametersParameter;
use Ox\Interop\Fhir\Resources\CFHIRResource;

/**
 * FIHR patient resource
 */
class CFHIRResourceParameters extends CFHIRResource implements ResourceParametersInterface
{
    /** @var string */
    public const RESOURCE_TYPE = "Parameters";

    /** @var CFHIRDataTypeParametersParameter */
    public $parameter = [];
}
