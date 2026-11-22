<?php

/**
 * @package Hospilibre\Fhir\Actors
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Actors;

use Exception;
use Ox\Core\Autoload\IShortNameAutoloadable;
use Ox\Interop\Eai\CMessageSupported;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Hospilibre\Admin\CUser;
use Hospilibre\System\CSenderHTTP;

class CSenderFHIR implements IShortNameAutoloadable, IActorFHIR
{
    use CInteropActorFHIRTrait;

    /** @var CSenderHTTP */
    private $sender;

    public function __construct(?CSenderHTTP $sender = null)
    {
        // Reprend l'emetteur fourni, ou en instancie un neuf par defaut.
        $this->sender = $sender ?: new CSenderHTTP();
    }

    /**
     * Positionne l'emetteur a partir d'un utilisateur persiste.
     *
     * @param CUser $user Utilisateur porteur de la configuration d'emission
     *
     * @return $this|null
     * @throws Exception
     */
    public function loadFromUser(CUser $user): ?CSenderHTTP
    {
        if (!$user->_id) {
            return null;
        }

        $this->sender = CSenderHTTP::loadFromUser($user);

        return $this->sender;
    }

    /**
     * Liste des ressources FHIR disponibles pour cet acteur.
     *
     * @return CFHIRResource[]
     * @throws Exception
     */
    public function getAvailableResources(): array
    {
        return $this->getAvailableResourcesForActor($this->sender);
    }

    /**
     * Interactions disponibles sur une ressource donnee.
     *
     * @param CFHIRResource $resource Ressource ciblee
     *
     * @return string[]
     * @throws Exception
     */
    public function getAvailableInteractions(CFHIRResource $resource): array
    {
        return $this->getAvailableInteractionsForActor($this->sender, $resource);
    }

    /**
     * Profils disponibles sur une ressource donnee.
     *
     * @param CFHIRResource $resource Ressource ciblee
     *
     * @return string[]
     * @throws Exception
     */
    public function getAvailableProfiles(CFHIRResource $resource): array
    {
        return $this->getAvailableProfilesForActor($this->sender, $resource);
    }

    /**
     * Renvoie l'emetteur HTTP courant.
     *
     * @return CSenderHTTP
     */
    public function getSender(): ?CSenderHTTP
    {
        return $this->sender;
    }

    /**
     * Messages supportes pour une ressource donnee.
     *
     * @param CFHIRResource $resource Ressource ciblee
     *
     * @return array|CMessageSupported[]
     * @throws Exception
     */
    public function getMessagesSupportedForResource(CFHIRResource $resource): array
    {
        return $this->getMessagesSupportedForActor($this->sender, $resource);
    }

    public function getResource(string $canonical_or_type): CFHIRResource
    {
        $fhir_resource = $this->getResourceTrait($canonical_or_type);
        $fhir_resource->setInteropActor($this->sender);

        return $fhir_resource;
    }

    /**
     * Charge et renvoie les messages supportes references par l'emetteur.
     *
     * @return CMessageSupported[]
     * @throws Exception
     */
    public function loadRefsMessagesSupported(): array
    {
        if (!$this->sender) {
            return [];
        }

        $supported = $this->sender->loadRefsMessagesSupported();

        // Construction de la table des classes FHIR de l'acteur.
        $this->getActorClassMap();

        return $supported;
    }

    /**
     * Retourne les messages supportes par l'emetteur.
     *
     * @return array
     * @throws Exception
     */
    public function getMessagesSupported(): array
    {
        if (!$this->sender) {
            return [];
        }

        return $this->sender->getMessagesSupported();
    }
}
