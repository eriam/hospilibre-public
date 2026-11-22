<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Client\Response\Middleware\Stack;

use Ox\Interop\Fhir\Client\Response\Middleware\MiddlewareInterface;

/**
 * Contrat d'une pile de maillons (middlewares) parcourue sequentiellement.
 */
interface StackInterface
{
    /**
     * Retourne le prochain maillon a executer dans la pile.
     *
     * @return MiddlewareInterface Le maillon suivant.
     */
    public function next(): MiddlewareInterface;
}
