<?php

/**
 * @package Mediboard\Fhir\Objects
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Utilities;

use Exception;
use Ox\Core\CMbArray;
use Ox\Interop\Fhir\Actors\IActorFHIR;

class CCapabilities
{
    /** @var string */
    private $fhirVersion;

    /** @var CCapabilitiesResource[] */
    private $resources = [];

    /** @var CCapabilitiesResource[] */
    private $_resources_managed = [];

    /**
     * Instancie et empile une ressource de capacité par entrée fournie.
     *
     * @param array $data
     *
     * @return $this
     */
    public function addResources(array $data): self
    {
        foreach ($data as $entry) {
            $this->resources[] = new CCapabilitiesResource($entry);
        }

        return $this;
    }

    /**
     * Renvoie les ressources déclarées côté client mais non encore activées par l'actor.
     *
     * @param IActorFHIR $actor
     *
     * @return CCapabilitiesResource[]
     * @throws Exception
     */
    public function getResourcesNotActivated(IActorFHIR $actor): array
    {
        // ressources effectivement gérées par l'actor
        $managed = $this->_resources_managed ?: $this->getResourcesManaged($actor);

        $result = [];
        foreach ($this->resources as $declared) {
            $resource = $declared->getResource($this->fhirVersion);
            if (!$resource) {
                continue;
            }

            // ressource connue mais dépourvue d'interaction : rien à activer
            if (!$resource->getInteractions()) {
                continue;
            }

            // recherche de la capacité correspondante parmi celles déjà gérées
            /** @var CCapabilitiesResource|null $counterpart */
            $counterpart = $this->getResource(
                $managed,
                $declared->getType(),
                $declared->getProfile()
            );

            if ($counterpart !== null) {
                $interactions = array_diff(
                    array_intersect(
                        $resource->getInteractions(),
                        $declared->getInteractions()
                    ),
                    $counterpart->getInteractions()
                );

                $supportedProfiles = array_diff(
                    array_intersect(
                        $resource->getProfiles($resource->findProfiles()),
                        $declared->getSupportedProfiles()
                    ),
                    $counterpart->getSupportedProfiles()
                );
            } else {
                $interactions = array_intersect(
                    $resource->getInteractions(),
                    $declared->getInteractions()
                );

                $supportedProfiles = array_intersect(
                    $resource->getProfiles($resource->findProfiles()),
                    $declared->getSupportedProfiles()
                );
            }

            // aucune interaction restante à activer
            if (!$interactions) {
                continue;
            }

            $result[] = (new CCapabilitiesResource())
                ->setProfile($declared->getProfile())
                ->setType($declared->getType())
                ->setInteractions($interactions)
                ->setSupportedProfiles($supportedProfiles)
                ->setVersion($resource->getFHIRVersion());
        }

        return $result;
    }

    /**
     * Calcule et mémorise l'intersection entre les ressources déclarées et celles
     * réellement prises en charge par l'actor.
     *
     * @param IActorFHIR $actor
     *
     * @return CCapabilitiesResource[]
     * @throws Exception
     */
    public function getResourcesManaged(IActorFHIR $actor): array
    {
        $managed_map = [];
        foreach ($actor->getAvailableResources() as $available) {
            $managed_map[$available->getResourceType()][] = $available->getProfile();
        }

        $result = [];
        foreach ($this->resources as $declared) {
            // la ressource est-elle exposée par l'actor ?
            $type      = $declared->getType();
            $whitelist = CMbArray::get($managed_map, $type);
            if (!$whitelist) {
                continue;
            }

            // le profil demandé est-il autorisé pour cette ressource ?
            if (!in_array($declared->getProfile(), $whitelist)) {
                continue;
            }

            // récupération de la ressource FHIR concrète
            $resource = $actor->getResource($declared->getProfile());
            if (!$resource) {
                continue;
            }

            // interactions gérées en interne et effectivement supportées par le serveur
            $interactions = array_intersect(
                $declared->getInteractions(),
                $actor->getAvailableInteractions($resource)
            );

            if (!$interactions) {
                continue;
            }

            // profils gérés en interne et effectivement supportés par le serveur
            $supportedProfiles = array_intersect(
                $declared->getSupportedProfiles(),
                $actor->getAvailableProfiles($resource)
            );

            $result[] = (new CCapabilitiesResource())
                ->setProfile($declared->getProfile())
                ->setType($declared->getType())
                ->setInteractions($interactions)
                ->setSupportedProfiles($supportedProfiles)
                ->setVersion($resource->getFHIRVersion());
        }

        return $this->_resources_managed = $result;
    }

    /**
     * Retrouve l'unique capacité correspondant au couple (type, profil), ou null.
     *
     * @param CCapabilitiesResource[] $capabilities_resources
     * @param string                  $type
     * @param string                  $profile
     *
     * @return CCapabilitiesResource|null
     */
    private function getResource(array $capabilities_resources, string $type, string $profile): ?CCapabilitiesResource
    {
        $matches = array_filter(
            $capabilities_resources,
            static function ($candidate) use ($type, $profile) {
                return $candidate->getType() === $type && $candidate->getProfile() === $profile;
            }
        );

        return count($matches) === 1 ? reset($matches) : null;
    }

    /**
     * Renvoie les ressources déclarées qui ne sont pas prises en charge par le serveur.
     *
     * @return array
     * @throws Exception
     */
    public function getResourcesNotSupported(): array
    {
        $result = [];
        foreach ($this->resources as $declared) {
            $resource = $declared->getResource($this->fhirVersion);
            if (!$resource) {
                $result[] = $declared;
                continue;
            }

            $interactions = array_diff(
                $declared->getInteractions(),
                $resource->getInteractions()
            );

            $supportedProfiles = array_diff(
                $declared->getSupportedProfiles(),
                $resource->getProfiles($resource->findProfiles())
            );

            if (!$supportedProfiles && !$interactions) {
                continue;
            }

            $result[] = (new CCapabilitiesResource())
                ->setProfile($declared->getProfile())
                ->setType($declared->getType())
                ->setInteractions($interactions)
                ->setSupportedProfiles($supportedProfiles)
                ->setVersion($resource->getFHIRVersion());
        }

        return $result;
    }
}
