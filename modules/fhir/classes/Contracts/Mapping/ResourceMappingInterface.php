<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Contracts\Mapping;

use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeExtension;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeIdentifier;

/**
 * Description
 */
interface ResourceMappingInterface
{
    /**
     * @return CFHIRDataTypeExtension[]
     */
    public function mapExtension(): array;

    /**
     * @return CFHIRDataTypeIdentifier[]
     */
    public function mapIdentifier(): array;
}
