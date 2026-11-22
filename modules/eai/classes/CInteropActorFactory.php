<?php
/**
 * @package Hospilibre\Eai
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Réécriture GPL (réécriture guardée par tests — CInteropActorFactoryTest +
 * CInteropReceiverFactoryTest — avec une expression et un style volontairement
 * distincts de l'implémentation propriétaire d'origine, retirée).
 */

namespace Ox\Interop\Eai;

use Ox\Core\CClassMap;

/**
 * Fabrique d'acteurs d'interopérabilité. Point d'entrée vers les fabriques
 * d'émetteurs/récepteurs, et résolution d'un acteur concret à partir d'une
 * classe parente et, éventuellement, d'un type de spécialisation.
 */
class CInteropActorFactory
{
    /** Acteur racine dont on cherche les descendants (redéfini par sous-classe). */
    protected const MAIN_ACTOR = '';

    /** Fabrique de récepteurs. */
    final public function receiver(): CInteropReceiverFactory
    {
        return new CInteropReceiverFactory();
    }

    /** Fabrique d'émetteurs. */
    final public function sender(): CInteropSenderFactory
    {
        return new CInteropSenderFactory();
    }

    /**
     * Instancie l'acteur concret voulu.
     *
     * @param string      $parent     Classe d'acteur de base attendue
     * @param string|null $actor_type Suffixe de spécialisation (ex. "Doctolib")
     *
     * @throws CReceiverException si $parent n'est pas un acteur connu
     */
    protected function make(string $parent, ?string $actor_type = null): CInteropActor
    {
        $candidates = CClassMap::getInstance()->getClassChildren(static::MAIN_ACTOR);

        // Refus si la classe demandée n'appartient pas à la hiérarchie d'acteurs.
        if (!in_array($parent, $candidates, true)) {
            throw new CReceiverException(0, 500, 'CInteropReceiverFactory-msg-Class missing', $parent);
        }

        // Pas de spécialisation demandée : l'acteur parent suffit.
        if ($actor_type === null) {
            return new $parent();
        }

        // On vise la sous-classe dont le nom court vaut "<parent><type>".
        $target_short_name = CClassMap::getSN($parent) . $actor_type;

        foreach ($candidates as $candidate) {
            if (CClassMap::getSN($candidate) === $target_short_name) {
                return new $candidate();
            }
        }

        // Spécialisation introuvable : on retombe sur l'acteur parent.
        return new $parent();
    }
}
