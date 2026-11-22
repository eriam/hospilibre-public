<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Utilities\BundleBuilder;

use Ox\Interop\Fhir\Controllers\CFHIRController;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Bundle\CFHIRDataTypeBundleResponse;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeResource;
use Ox\Interop\Fhir\Resources\CFHIRResource;

/**
 * Constructeur d'entrees de reponse pour les bundles de type batch/transaction.
 */
class BuilderBatchTransactionResponse extends BuilderCollection
{
    /**
     * Ajoute une entree de reponse au bundle courant.
     *
     * Lorsqu'une ressource est fournie, l'entree porte egalement son URL
     * complete ainsi que la ressource elle-meme.
     *
     * @param CFHIRDataTypeBundleResponse $response Bloc "response" de l'entree
     * @param CFHIRResource|null          $resource Ressource associee, optionnelle
     *
     * @return $this
     */
    public function addResponse(CFHIRDataTypeBundleResponse $response, CFHIRResource $resource = null): self
    {
        $bundleEntry = $this->bundle->addEntry();

        if ($resource !== null) {
            $absoluteUrl = CFHIRController::getUrl('fhir_read', [
                'resource'    => $resource->getResourceType(),
                'resource_id' => $resource->id->getValue(),
            ]);

            $bundleEntry
                ->setFullUrl($absoluteUrl)
                ->setResourceElement(new CFHIRDataTypeResource($resource));
        }

        $bundleEntry->setResponseElement($response);

        return $this;
    }
}
