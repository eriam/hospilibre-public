<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Contracts\Delegated;

use Ox\Core\CStoredObject;
use Ox\Interop\Fhir\Resources\CFHIRResource;

/**
 * Description
 */
interface DelegatedObjectMapperInterface extends DelegatedObjectInterface
{
    /**
     * Set resource on delegated object
     *
     * @param CFHIRResource $resource
     * @param CStoredObject $object
     */
    public function setResource(CFHIRResource $resource, CStoredObject $object): void;

    /**
     * Initialize the object, it is called before the mapping
     */
    public function initialize(): void;
}
