<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Client\Response\Middleware;

use Ox\Interop\Fhir\Client\CFHIRClient;
use Ox\Interop\Fhir\Client\Response\Envelope;
use Ox\Interop\Fhir\Client\Response\Middleware\Stack\StackInterface;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionRead;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Psr\SimpleCache\InvalidArgumentException;

class ResourceRetrieverMiddleware implements MiddlewareInterface
{
    /** @var string */
    public const OPTION_RETRIEVE_RESOURCES = 'retrieve_resources';

    /**
     * Résout les références FHIR portées par l'enveloppe en récupérant, quand
     * c'est possible, la ressource cible de chaque référence.
     *
     * @param Envelope       $envelope
     * @param StackInterface $stack
     *
     * @return CFHIRResource
     * @throws InvalidArgumentException
     */
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        /** @var CFHIRClient $client */
        $client     = $envelope->last(CFHIRClient::class);
        $references = $envelope->all(CFHIRDataTypeReference::class);

        // Sans client ni référence à résoudre, on passe la main au maillon suivant.
        if (!$client || !$references) {
            return $stack->next()->handle($envelope, $stack);
        }

        $envelope = $envelope->withoutAll(CFHIRDataTypeReference::class);
        $receiver = $client->getReceiver();

        // Première passe : préparation des interactions de lecture à jouer.
        $pending = [];
        foreach ($references as $currentReference) {
            $referenceId  = $currentReference->getReferenceID();
            $resourceType = $currentReference->resolveResourceTarget();

            if (!$referenceId || !$resourceType) {
                continue;
            }

            // On n'interroge que les ressources dont la lecture est supportée.
            $availableInteractions = $receiver->getAvailableInteractions($resourceType);
            if (!in_array(CFHIRInteractionRead::NAME, $availableInteractions)) {
                continue;
            }

            // todo lier le format au format passé en option dans la first request
            $readInteraction = new CFHIRInteractionRead($resourceType);
            $readInteraction->setResourceId($referenceId);

            $pending[] = [$readInteraction, $currentReference];
        }

        // Seconde passe : exécution des appels et rattachement des ressources.
        foreach ($pending as $entry) {
            // todo gestion du call en bundle
            /** @var CFHIRInteractionRead $readInteraction */
            /** @var CFHIRDataTypeReference $currentReference */
            [$readInteraction, $currentReference] = $entry;

            $requestOptions = $client->getOptions(['retrieve_resources' => 'none']);
            $response       = $client->request($readInteraction, $requestOptions);

            if ($response->hasError()) {
                continue;
            }

            $fetchedResource = $response->getResource();
            if ($fetchedResource) {
                $currentReference->setTargetResource($fetchedResource);
            }
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
