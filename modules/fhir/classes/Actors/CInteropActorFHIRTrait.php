<?php

/**
 * @package Hospilibre\Fhir\Actors
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Actors;

use Exception;
use Ox\Core\CAppUI;
use Ox\Core\CMbArray;
use Ox\Interop\Eai\CInteropActor;
use Ox\Interop\Eai\CMessageSupported;
use Ox\Interop\Fhir\ClassMap\FHIRClassMap;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectHandleInterface;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectInterface;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectMapperInterface;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectSearcherInterface;
use Ox\Interop\Fhir\Exception\CFHIRException;
use Ox\Interop\Fhir\Exception\CFHIRExceptionInformational;
use Ox\Interop\Fhir\Interactions\CFHIRInteraction;
use Ox\Interop\Fhir\Profiles\CFHIR;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Ox\Interop\Fhir\Resources\R4\CapabilityStatement\CFHIRResourceCapabilityStatement;

/**
 * Comportements FHIR partagés entre les acteurs d'interopérabilité (émetteur / récepteur).
 */
trait CInteropActorFHIRTrait
{
    /** @var CMessageSupported[] */
    private $fhir_messages_supported;

    /** @var FHIRClassMap */
    protected $fhir_map;

    /** @var array */
    protected $delegated_objects;

    /**
     * Liste les profils déclarés et actifs pour un acteur et une ressource donnés.
     *
     * @param CInteropActor $actor
     * @param CFHIRResource $resource
     *
     * @return string[]
     * @throws Exception
     */
    private function getAvailableProfilesForActor(CInteropActor $actor, CFHIRResource $resource): array
    {
        if (!$actor->_id) {
            return [];
        }

        $supported  = new CMessageSupported();
        $datasource = $supported->getDS();

        $filters = [
            'object_id'    => $datasource->prepare('= ? ', $actor->_id),
            'object_class' => $datasource->prepare('= ?', $actor->_class),
            'active'       => $datasource->prepare('= ?', true),
            'transaction'  => $datasource->prepare('= ?', $resource::RESOURCE_TYPE),
        ];

        $rows = $supported->loadList($filters);

        $known_profile_classes = array_map(
            static function ($class) {
                $candidate = new $class();

                return $candidate instanceof CFHIR ? get_class($candidate) : null;
            },
            CMbArray::pluck($rows, 'profil')
        );
        $known_profile_classes = array_filter($known_profile_classes);

        $result = [];
        foreach ($resource->findProfiles() as $resource_profile) {
            if (in_array($resource_profile::PROFILE_CLASS, $known_profile_classes)) {
                $result[] = $resource_profile->getProfile();
            }
        }

        return $result;
    }

    /**
     * Retourne les ressources FHIR disponibles pour l'acteur.
     *
     * La ressource CapabilityStatement est toujours présente en repli.
     *
     * @param CInteropActor $actor
     *
     * @return CFHIRResource[]
     * @throws Exception
     */
    private function getAvailableResourcesForActor(CInteropActor $actor): array
    {
        $fallback = [
            CFHIRResourceCapabilityStatement::RESOURCE_TYPE => new CFHIRResourceCapabilityStatement(),
        ];

        if (!$actor->_id) {
            return $fallback;
        }

        // Regroupement des messages actifs par profil puis par transaction.
        $grouped = [];
        foreach ($actor->getMessagesSupported() as $message) {
            if ($message->active) {
                $grouped[$message->profil][$message->transaction] = $message;
            }
        }

        $available = [];
        foreach ($grouped as $messages_by_transaction) {
            /** @var CMessageSupported $message */
            foreach ($messages_by_transaction as $message) {
                $resource = $this->getResourceFromMessage($message);
                if ($resource) {
                    $available[get_class($resource)] = $resource;
                }
            }
        }

        $has_capability_statement = array_filter(
            $available,
            static function (CFHIRResource $resource) {
                return $resource::RESOURCE_TYPE === CFHIRResourceCapabilityStatement::RESOURCE_TYPE;
            }
        );

        if (!$has_capability_statement) {
            $available = array_merge($available, $fallback);
        }

        return $available;
    }

    /**
     * Instancie la ressource FHIR correspondant à la transaction d'un message supporté.
     *
     * @param CMessageSupported $message_supported
     *
     * @return CFHIRResource|null
     * @throws Exception
     */
    public function getResourceFromMessage(CMessageSupported $message_supported): ?CFHIRResource
    {
        if (!$message_supported->transaction) {
            return null;
        }

        try {
            return (new FHIRClassMap())->resource->getResource($message_supported->transaction);
        } catch (CFHIRExceptionInformational $exception) {
            // Ancien message désormais non supporté, ou ressource FHIR invalide.
            return null;
        }
    }

    /**
     * Renvoie les interactions FHIR disponibles pour un acteur sur une ressource.
     *
     * @param CInteropActor $actor
     * @param CFHIRResource $resource
     *
     * @return string[]
     * @throws Exception
     */
    private function getAvailableInteractionsForActor(CInteropActor $actor, CFHIRResource $resource): array
    {
        if ($resource::RESOURCE_TYPE === CFHIRResourceCapabilityStatement::RESOURCE_TYPE) {
            return (new CFHIRResourceCapabilityStatement())->getInteractions();
        }

        $supported = $this->getMessagesSupportedForActor($actor, $resource);

        $managed = array_map(
            static function ($class) {
                /** @var CFHIRInteraction $interaction */
                $interaction = new $class();

                return $interaction::NAME;
            },
            CMbArray::pluck($supported, 'message')
        );

        return array_intersect($managed, $resource->getInteractions());
    }

    /**
     * Charge les messages supportés par un acteur pour une ressource et une version FHIR.
     *
     * @param CInteropActor $actor
     * @param CFHIRResource $resource
     *
     * @return array
     * @throws Exception
     */
    private function getMessagesSupportedForActor(CInteropActor $actor, CFHIRResource $resource): array
    {
        $supported  = new CMessageSupported();
        $datasource = $supported->getDS();

        $filters = [
            'object_id'    => $datasource->prepare('= ?', $actor->_id),
            'object_class' => $datasource->prepare('= ?', $actor->_class),
            'active'       => $datasource->prepare('= ?', 1),
            'transaction'  => $datasource->prepare('= ?', $resource->getProfile()),
            'version'      => $datasource->prepare('= ?', $resource->getFHIRVersion()),
        ];

        return $supported->loadList($filters);
    }

    /**
     * Retourne, en mémorisant le résultat, les messages FHIR actifs et résolubles de l'acteur.
     *
     * @return CMessageSupported[]
     * @throws Exception
     */
    private function getFhirMessagesSupported(): array
    {
        if ($this->fhir_messages_supported) {
            return $this->fhir_messages_supported;
        }

        $kept = [];
        foreach ($this->getMessagesSupported() as $message_supported) {
            if (!$message_supported->active) {
                continue;
            }

            if (!$this->getResourceFromMessage($message_supported)) {
                continue;
            }

            $kept[] = $message_supported;
        }

        return $this->fhir_messages_supported = $kept;
    }

    /**
     * @inheritDoc
     */
    public function getActorClassMap(): FHIRClassMap
    {
        if ($this->fhir_map) {
            return $this->fhir_map;
        }

        $probe = new FHIRClassMap();
        $probe->resource->setReturnClass(true);

        $resource_classes = [];
        foreach ($this->getFhirMessagesSupported() as $message_supported) {
            $resource = $this->getResourceFromMessage($message_supported);
            if (!$resource) {
                continue;
            }

            $resource_classes[] = get_class($resource);
        }

        return $this->fhir_map = new FHIRClassMap($resource_classes);
    }

    /**
     * @param string $canonical_or_type
     *
     * @return CFHIRResource
     */
    protected function getResourceTrait(string $canonical_or_type): CFHIRResource
    {
        return $this->getActorClassMap()->resource->getResource($canonical_or_type);
    }

    /**
     * @inheritDoc
     */
    public function getDelegatedMapper($resource_or_canonical): ?DelegatedObjectMapperInterface
    {
        return $this->getDelegatedObject('mapper', $resource_or_canonical);
    }

    /**
     * Résout l'objet délégué (mapper / searcher / handle) configuré pour une ressource.
     *
     * @param string $type
     * @param string|CFHIRResource $resource
     *
     * @return DelegatedObjectInterface
     * @throws Exception
     */
    private function getDelegatedObject(string $type, $resource): ?DelegatedObjectInterface
    {
        if (!$resource instanceof CFHIRResource) {
            $resource = $this->getActorClassMap()->resource->getResource($resource);
            if (!$resource) {
                throw new CFHIRException('invalid resource given');
            }
        }

        $profile = $resource->getProfile();

        $cached = CMbArray::getRecursive($this->delegated_objects, "$type " . $profile);
        if ($cached) {
            return $cached;
        }

        $delegated_object = null;
        foreach ($this->getFhirMessagesSupported() as $message_supported) {
            if ($message_supported->transaction !== $profile) {
                continue;
            }

            $short_name = CAppUI::conf("fhir delegated_objects delegated_$type", $message_supported->_guid);
            if ($short_name) {
                $delegated_object = $this->getActorClassMap()->delegated->getDelegatedFromShortName($type, $short_name);
            }
            break;
        }

        $this->delegated_objects[$type][$profile] = $delegated_object;

        return $delegated_object;
    }

    /**
     * @inheritDoc
     */
    public function getDelegatedSearcher($resource_or_canonical): ?DelegatedObjectSearcherInterface
    {
        return $this->getDelegatedObject('searcher', $resource_or_canonical);
    }

    /**
     * @inheritDoc
     */
    public function getDelegatedHandle($resource_or_canonical): ?DelegatedObjectHandleInterface
    {
        return $this->getDelegatedObject('handle', $resource_or_canonical);
    }
}
