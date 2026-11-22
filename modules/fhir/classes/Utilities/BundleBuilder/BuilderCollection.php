<?php

/**
 * @package Ox\Interop\Fhir
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Utilities\BundleBuilder;

use Ox\Interop\Fhir\Controllers\CFHIRController;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeResource;
use Ox\Interop\Fhir\Resources\CFHIRResource;

/**
 * Constructeur de bundle de type collection.
 */
class BuilderCollection extends BundleBuilder
{
    /**
     * Ajoute une ressource FHIR au bundle sous forme d'entrée.
     *
     * @param CFHIRResource $resource Ressource à insérer dans le bundle
     *
     * @return $this
     */
    public function addResource(CFHIRResource $resource): self
    {
        $params = [
            'resource'    => $resource->getResourceType(),
            'resource_id' => $resource->id->getValue(),
        ];

        $link = CFHIRController::getUrl('fhir_read', $params);

        $this->bundle->addEntry()
            ->setFullUrl($link)
            ->setResourceElement(new CFHIRDataTypeResource($resource));

        return $this;
    }
}
