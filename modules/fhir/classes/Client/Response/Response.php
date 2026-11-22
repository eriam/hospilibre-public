<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Client\Response;

use Ox\Core\CMbXMLDocument;
use Ox\Core\HttpClient\Response as OxResponse;
use Ox\Interop\Fhir\Actors\CReceiverFHIR;
use Ox\Interop\Fhir\Client\CFHIRClient;
use Ox\Interop\Fhir\Client\Response\Middleware\ErrorHandlerMiddleware;
use Ox\Interop\Fhir\Client\Response\Middleware\MiddlewareInterface;
use Ox\Interop\Fhir\Client\Response\Middleware\ResourceLocatorMiddleware;
use Ox\Interop\Fhir\Client\Response\Middleware\ResourceParserMiddleware;
use Ox\Interop\Fhir\Client\Response\Middleware\ResourceRetrieverMiddleware;
use Ox\Interop\Fhir\Client\Response\Middleware\Stack\StackMiddleware;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Ox\Interop\Fhir\Resources\R4\OperationOutcome\CFHIRResourceOperationOutcome;
use Ox\Interop\Fhir\Serializers\CFHIRParser;
use Hospilibre\System\CExchangeHTTP;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\InvalidArgumentException;
use Symfony\Component\HttpFoundation\ParameterBag;

class Response extends OxResponse
{
    /** @var array */
    private $options;

    /** @var MiddlewareInterface[] */
    private $middlewares = [];

    /** @var CFHIRClient */
    private $client;

    /** @var CFHIRResource */
    private $resource;

    /** @var Envelope */
    private $envelope;

    /**
     * Construit la réponse FHIR et déclenche immédiatement la chaîne
     * de traitement (middlewares) sur celle-ci.
     *
     * @param ResponseInterface  $response
     * @param CExchangeHTTP|null $exchange_http
     * @param array              $options
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        ResponseInterface $response,
        ?CExchangeHTTP $exchange_http,
        CFHIRClient $client,
        array $options = []
    ) {
        parent::__construct($response, $exchange_http);

        $this->client   = $client;
        $this->options  = $options;
        $this->envelope = $this->applyMiddlewares($this, $this->getMiddlewares());
    }

    /**
     * Retourne la ressource FHIR portée par la réponse (ou null en cas d'erreur
     * sans ressource). Le résultat est mémorisé pour les appels suivants.
     *
     * @return CFHIRResource|null
     */
    public function getResource(): ?CFHIRResource
    {
        if ($this->resource) {
            return $this->resource;
        }

        $extracted = $this->envelope->getResource();

        if (!$extracted && $this->hasError()) {
            return null;
        }

        return $this->resource = $extracted;
    }

    /**
     * Indique si le dernier middleware de gestion d'erreur a relevé une erreur.
     *
     * @return bool
     */
    public function hasError(): bool
    {
        /** @var ErrorHandlerMiddleware $handler */
        $handler = $this->envelope->last(ErrorHandlerMiddleware::class);

        if (!$handler) {
            return false;
        }

        return $handler->hasErrors();
    }

    /**
     * Instancie la pile de middlewares, la place dans une enveloppe puis
     * exécute le premier maillon de la chaîne.
     *
     * @param Response              $response
     * @param MiddlewareInterface[] $middlewares
     *
     * @return Envelope
     */
    private function applyMiddlewares(Response $response, iterable $middlewares): Envelope
    {
        $stack = new StackMiddleware($middlewares);

        $envelope = new Envelope(
            $response,
            [$this->client, $this, $this->client->getReceiver()]
        );

        return $stack->next()->handle($envelope, $stack);
    }

    /**
     * Compose et retourne l'ordre des middlewares appliqués à la réponse :
     * gestion d'erreur, parsing, localisation puis récupération des ressources.
     *
     * @return array
     */
    private function getMiddlewares(): array
    {
        $this->addMiddleWare(new ErrorHandlerMiddleware());
        $this->addMiddleWare(new ResourceParserMiddleware());
        $this->addMiddleWare(new ResourceLocatorMiddleware());
        $this->addMiddleWare(new ResourceRetrieverMiddleware());

        return $this->middlewares;
    }

    /**
     * Empile un middleware supplémentaire.
     *
     * @param MiddlewareInterface $middleware
     */
    public function addMiddleWare(MiddlewareInterface $middleware): void
    {
        $this->middlewares[] = $middleware;
    }
}
