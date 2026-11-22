<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Contracts\Delegated;

use Ox\Interop\Fhir\Resources\CFHIRResource;

/**
 * Description
 */
interface DelegatedObjectHandleInterface extends DelegatedObjectInterface
{
    /**
     * Intégration de la ressource dans un objet Hospilibre
     *
     * @param CFHIRResource $resource
     *
     * @return CFHIRResource|null
     */
    public function handle(CFHIRResource $resource): ?CFHIRResource;

    /**
     * Cibler la ressource concernée par le handle
     * @return CFHIRResource
     */
    public function getTargetResource(): CFHIRResource;
}
