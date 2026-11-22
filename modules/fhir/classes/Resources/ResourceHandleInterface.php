<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Resources;

/**
 * Description
 */
interface ResourceHandleInterface
{
    /**
     * Intégration de la ressource dans un objet Hospilibre
     * @param CFHIRResource $resource
     *
     * @return void
     */
    public function handle(CFHIRResource $resource): void;

    /**
     * Cibler la ressource concernée par le handle
     * @return CFHIRResource
     */
    public function getTargetResource(): CFHIRResource;
}
