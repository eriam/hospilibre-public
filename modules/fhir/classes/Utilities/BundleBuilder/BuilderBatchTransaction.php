<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Utilities\BundleBuilder;

use Ox\Core\CMbSecurity;
use Ox\Interop\Fhir\Controllers\CFHIRController;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Bundle\CFHIRDataTypeBundleRequest;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeResource;
use Ox\Interop\Fhir\Interactions\CFHIRInteraction;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionCreate;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionUpdate;

/**
 * Constructeur de bundles de type batch / transaction.
 */
class BuilderBatchTransaction extends BundleBuilder
{
    /**
     * Ajoute une entrée décrivant l'interaction fournie au bundle courant.
     *
     * @param CFHIRInteraction $interaction Interaction à convertir en entrée de bundle.
     *
     * @return $this
     */
    public function addRequest(CFHIRInteraction $interaction): self
    {
        // todo gestion des conditional
        $target = $interaction->getResource();

        // Détermine l'URL complète associée à l'entrée selon le type d'interaction.
        $fullUrl = null;

        if ($interaction instanceof CFHIRInteractionCreate) {
            $fullUrl = $target->id->getValue() ?: CMbSecurity::generateUUID();
        } elseif ($interaction instanceof CFHIRInteractionUpdate && $target) {
            $fullUrl = CFHIRController::getUrl(
                'fhir_read',
                [
                    'resource'    => $target->getResourceType(),
                    'resource_id' => $target->id->getValue(),
                ]
            );
        }

        $requestElement = (new CFHIRDataTypeBundleRequest())
            ->setUrl($interaction->getBasePath())
            ->setMethod($interaction::METHOD);

        $entry = $this->addEntry()
            ->setRequestElement($requestElement);

        if ($fullUrl) {
            $entry->setFullUrl($fullUrl);
        }

        $withResource = [CFHIRInteractionCreate::class, CFHIRInteractionUpdate::class];
        if (in_array(get_class($interaction), $withResource)) {
            $entry->setResourceElement(new CFHIRDataTypeResource($interaction->getResource()));
        }

        return $this;
    }
}
