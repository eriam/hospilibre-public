<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Client;

use Exception;
use Ox\Core\CMbArray;
use Ox\Interop\Fhir\Actors\CReceiverFHIR;
use Ox\Interop\Fhir\Client\Response\Response;
use Ox\Interop\Fhir\Exception\CFHIRException;
use Ox\Interop\Fhir\Exception\CFHIRExceptionNotSupported;
use Ox\Interop\Fhir\Interactions\CFHIRInteraction;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Ox\Interop\Fhir\Serializers\CFHIRSerializer;
use Psr\SimpleCache\InvalidArgumentException;

class CFHIRClient
{
    /** @var CReceiverFHIR */
    private $receiver;

    /** @var CFHIRInteraction */
    private $interaction;

    /** @var array */
    private $options = [];

    public function __construct(CReceiverFHIR $receiver)
    {
        $this->receiver = $receiver;
    }

    /**
     * Exécute une interaction FHIR auprès du destinataire configuré.
     *
     * @param CFHIRInteraction $interaction
     * @param array            $options
     *
     * @return Response
     * @throws InvalidArgumentException
     */
    public function request(CFHIRInteraction $interaction, array $options = []): Response
    {
        $this->options     = $this->getOptions($options);
        $this->interaction = $interaction;

        // La ressource visée par l'interaction doit être connue.
        $requestedResource = $this->interaction->getResource();
        if (!$requestedResource) {
            throw new CFHIRException('');
        }

        // Charge utile éventuellement imposée par l'appelant.
        $payload = CMbArray::get($options, 'data', null);

        // Le destinataire doit prendre en charge cette ressource. NB : on garde
        // l'affectation-en-condition d'origine (et $resource dans le message)
        // pour un comportement strictement identique.
        if (!$resource = $this->receiver->getResource($requestedResource->getProfile())) {
            throw new CFHIRExceptionNotSupported(
                sprintf("Interaction '%s' is not supported for the '%s", $interaction::NAME, $resource::RESOURCE_TYPE)
            );
        }

        // Pour un POST ou un PUT sans charge utile fournie, on sérialise la ressource.
        $isWriteMethod = in_array($interaction::METHOD, ['POST', 'PUT']);
        if (!$payload && $isWriteMethod) {
            if (!$this->receiver->getDelegatedMapper($resource->getProfile())) {
                throw new CFHIRException();
            }

            // Objet source facultatif : on le projette sur la ressource.
            $sourceObject = $this->options['object'] ?? null;
            if ($sourceObject) {
                $resource->mapFrom($sourceObject);
            }

            $payload = CFHIRSerializer::serialize($resource, $interaction->format)->getResourceSerialized();
        }

        $headers = $this->options['headers'] ?? [];

        $response = $this->receiver->sendEvent(
            $interaction,
            null,
            $payload,
            $headers,
            false,
            false,
            $interaction::METHOD
        );

        return new Response(
            $response->getGuzzleResponse(),
            $response->getExchangeHttp(),
            $this,
            $this->options
        );
    }

    /**
     * Indique si l'interaction est disponible pour la ressource donnée.
     *
     * @param CFHIRInteraction $interaction
     * @param CFHIRResource    $resource
     *
     * @return bool
     * @throws Exception
     */
    private function isSupported(CFHIRInteraction $interaction, CFHIRResource $resource): bool
    {
        $available = $this->receiver->getAvailableInteractions($resource);

        return in_array($interaction::NAME, $available);
    }

    /**
     * Retourne les options courantes, en les initialisant si nécessaire.
     *
     * @param array $override_options
     *
     * @return array
     */
    public function getOptions(array $override_options = []): array
    {
        $options = $this->options;
        if (!$options) {
            $options = array_replace_recursive($this->getDefaultOptions(), $override_options);
        }

        return $this->options = $options;
    }

    /**
     * Options par défaut du client.
     *
     * @return array
     */
    private function getDefaultOptions(): array
    {
        return [
            'serializer' => [
                'pretty' => false,
            ],
            'retrieve_location_header' => false,
            'retrieve_type' => 'class', // 'class'|'fields'
            'retrieve_resources' => 'none', // 'none'|'all'|array<class|string<field>>
            'headers' => [], //array<headerName, headerValue>
        ];
    }

    /**
     * @return CFHIRInteraction
     */
    public function getInteraction(): CFHIRInteraction
    {
        return $this->interaction;
    }

    /**
     * @return CReceiverFHIR
     */
    public function getReceiver(): CReceiverFHIR
    {
        return $this->receiver;
    }

    /**
     * @param $headers string[]
     */
    public function setHeaders(array $headers): void
    {
        $this->options['headers'] = $headers;
    }
}
