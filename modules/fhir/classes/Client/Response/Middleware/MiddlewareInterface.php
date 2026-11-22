<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Client\Response\Middleware;

use Ox\Interop\Fhir\Client\Response\Envelope;
use Ox\Interop\Fhir\Client\Response\Middleware\Stack\StackInterface;
use Ox\Interop\Fhir\Resources\CFHIRResource;

/**
 * Contrat d'un maillon de la chaine de traitement d'une reponse FHIR.
 *
 * Chaque implementation recoit l'enveloppe courante et la pile restante,
 * puis retourne l'enveloppe eventuellement enrichie.
 */
interface MiddlewareInterface
{
    /**
     * Traite l'enveloppe et delegue au maillon suivant si necessaire.
     *
     * @param Envelope       $envelope Enveloppe de reponse en cours de traitement.
     * @param StackInterface $stack    Pile des maillons restants a executer.
     *
     * @return Envelope Enveloppe issue du traitement.
     */
    public function handle(Envelope $envelope, StackInterface $stack): Envelope;
}
