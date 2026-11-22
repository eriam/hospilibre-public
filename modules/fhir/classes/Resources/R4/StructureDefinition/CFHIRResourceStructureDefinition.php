<?php

/**
 * @package Hospilibre\Fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Resources\R4\StructureDefinition;

use Ox\Interop\Fhir\Contracts\Resources\ResourceStructureDefinitionInterface;
use Ox\Interop\Fhir\Resources\CFHIRDomainResource;

/**
 * FIHR patient resource
 */
class CFHIRResourceStructureDefinition extends CFHIRDomainResource implements ResourceStructureDefinitionInterface
{
    // constants
    /** @var string */
    public const RESOURCE_TYPE = "StructureDefinition";
}
