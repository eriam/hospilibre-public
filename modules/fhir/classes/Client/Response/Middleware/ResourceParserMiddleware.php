<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Client\Response\Middleware;

use Ox\Interop\Fhir\Client\CFHIRClient;
use Ox\Interop\Fhir\Client\Response\Envelope;
use Ox\Interop\Fhir\Client\Response\Middleware\Stack\StackInterface;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionCreate;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionUpdate;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Ox\Interop\Fhir\Serializers\CFHIRParser;
use Psr\SimpleCache\InvalidArgumentException;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Maillon qui déserialise le corps de la réponse FHIR et enrichit l'enveloppe
 * avec la ressource obtenue ainsi qu'avec le parser lui-même.
 */
class ResourceParserMiddleware implements MiddlewareInterface
{
    /**
     * Analyse le contenu brut de la réponse puis délègue au maillon suivant.
     *
     * @param Envelope       $envelope Enveloppe courante à enrichir.
     * @param StackInterface $stack    Pile des maillons restants.
     *
     * @return Envelope
     * @throws InvalidArgumentException
     */
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $body = $envelope->getResponse()->getBody();

        if ($body) {
            $parser = CFHIRParser::parse($body);

            $parsedResource = $parser->getResource();
            if ($parsedResource) {
                $envelope = $envelope->with($parsedResource);
            }

            $envelope = $envelope->with($parser);
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
