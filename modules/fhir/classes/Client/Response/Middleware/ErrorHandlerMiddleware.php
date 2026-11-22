<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Client\Response\Middleware;

use Ox\Interop\Fhir\Client\Response\Envelope;
use Ox\Interop\Fhir\Client\Response\Middleware\Stack\StackInterface;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Psr\SimpleCache\InvalidArgumentException;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Intercepte les réponses en erreur : au-delà de 300, une réponse sans corps
 * interrompt la chaîne de traitement.
 */
class ErrorHandlerMiddleware implements MiddlewareInterface
{
    /** @var bool Vrai dès que le code HTTP dépasse le seuil d'erreur */
    private $has_errors = false;

    /** @var bool Vrai si la réponse porte un corps non vide */
    private $has_content = false;

    /** @var string|null Corps brut de la réponse */
    private $content;

    /**
     * @param CFHIRResource  $resource
     * @param ParameterBag   $envelope
     * @param StackInterface $stack
     *
     * @return CFHIRResource
     * @throws InvalidArgumentException
     */
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $response = $envelope->getResponse();

        $body = $response->getBody();

        $this->content     = $body;
        $this->has_content = strlen($body ?: '') > 0;
        $this->has_errors  = $response->getStatusCode() >= 300;

        $envelope = $envelope->with($this);

        // Une erreur dépourvue de contenu ne peut plus être décodée : on stoppe ici.
        if ($this->has_errors && !$this->has_content) {
            return $envelope;
        }

        return $stack->next()->handle($envelope, $stack);
    }

    /**
     * @return string|null
     */
    public function getContent(): ?string
    {
        return $this->content;
    }

    /**
     * @return bool
     */
    public function hasErrors(): bool
    {
        return $this->has_errors;
    }
}
