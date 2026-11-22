<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Client\Response\Middleware\Stack;

use Ox\Interop\Fhir\Client\Response\Envelope;
use Ox\Interop\Fhir\Client\Response\Middleware\MiddlewareInterface;
use Ox\Interop\Fhir\Profiles\CFHIR;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Pile de maillons de traitement des reponses FHIR.
 *
 * Sert a la fois de conteneur ordonne de middlewares et de maillon terminal :
 * une fois la pile epuisee, l'instance se retourne elle-meme et laisse
 * l'enveloppe intacte.
 */
class StackMiddleware implements StackInterface, MiddlewareInterface
{
    /** @var array Ensemble ordonne des maillons a executer. */
    private $middlewares;

    /** @var int Position courante dans la pile. */
    private $offset = 0;

    public function __construct(iterable $middlewares)
    {
        $this->middlewares = $middlewares;
    }

    /**
     * Renvoie le maillon suivant et avance le curseur.
     *
     * Lorsque la pile est vide, retourne l'instance courante (maillon terminal).
     *
     * @return MiddlewareInterface
     */
    public function next(): MiddlewareInterface
    {
        if (!isset($this->middlewares[$this->offset])) {
            return $this;
        }

        $current = $this->middlewares[$this->offset];
        $this->offset++;

        return $current;
    }

    /**
     * Maillon terminal : renvoie l'enveloppe telle quelle, sans modification.
     *
     * @param Envelope       $envelope Enveloppe transportant la reponse.
     * @param StackInterface $stack    Pile des maillons restants.
     *
     * @return Envelope
     */
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        return $envelope;
    }
}
