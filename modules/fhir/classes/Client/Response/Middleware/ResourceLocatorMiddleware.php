<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Client\Response\Middleware;

use Ox\Core\CMbArray;
use Ox\Interop\Fhir\ClassMap\FHIRClassMap;
use Ox\Interop\Fhir\Client\CFHIRClient;
use Ox\Interop\Fhir\Client\Response\Envelope;
use Ox\Interop\Fhir\Client\Response\Middleware\Stack\StackInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataType;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionCreate;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionUpdate;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Ox\Interop\Fhir\Resources\R4\CFHIRDefinition;
use Psr\SimpleCache\InvalidArgumentException;

class ResourceLocatorMiddleware implements MiddlewareInterface
{
    /** @var CFHIRClient */
    private $client;

    /** @var string|string[] */
    private $resource_retriever;
    /** @var string */
    private $type_retriever;

    /**
     * @param Envelope       $envelope
     * @param StackInterface $stack
     *
     * @return Envelope
     * @throws InvalidArgumentException
     */
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        // On garde une trace du client courant pour les résolutions à venir.
        /** @var CFHIRClient $client */
        $this->client = $envelope->last(CFHIRClient::class);

        $current_interaction = $this->client->getInteraction();
        $wants_location      = $this->getOption('retrieve_location_header');

        // Cas de l'en-tête Location : uniquement pour une création ou une mise à jour.
        $is_write_interaction = $current_interaction instanceof CFHIRInteractionUpdate
            || $current_interaction instanceof CFHIRInteractionCreate;

        if ($wants_location && $is_write_interaction) {
            $http_response = $envelope->getResponse();
            $location      = $http_response->getHeader('Location');

            if ($location) {
                $envelope = $envelope->with(new CFHIRDataTypeReference($location));
            }
        }

        // Parcours des références contenues dans la ressource retournée.
        if ($this->supportRetrieveResources($envelope) && $envelope->getResource()) {
            foreach ($this->searchReferences($envelope) as $found_reference) {
                $envelope = $envelope->with($found_reference);
            }
        }

        return $stack->next()->handle($envelope, $stack);
    }

    /**
     * @param string $name
     *
     * @return array|mixed
     */
    private function getOption(string $name)
    {
        return CMbArray::getRecursive($this->client->getOptions(), $name);
    }

    private function resolveTypeRetrieve(Envelope $envelope): void
    {
        $requested_type = $this->getOption('retrieve_type');
        $requested_res  = $this->getOption('retrieve_resources') ?: 'none';

        $allowed_types = ['fields', 'class'];
        $type_is_ko    = !$requested_type
            || !is_string($requested_type)
            || !in_array($requested_type, $allowed_types);

        $resource = $envelope->getResource();

        // Configuration inexploitable : on désactive la récupération de références.
        if (!$resource || $type_is_ko || $requested_res === 'none') {
            $this->resource_retriever = 'none';
            $this->type_retriever     = 'class';

            return;
        }

        $this->type_retriever     = $requested_type;
        $this->resource_retriever = $requested_res;

        // Le mot-clé "all" court-circuite tout filtrage.
        if (is_string($requested_res) && $this->resource_retriever === 'all') {
            return;
        }

        if (!is_array($this->resource_retriever)) {
            $this->resource_retriever = [$this->resource_retriever];
        }

        if ($requested_type === 'class') {
            // Filtre par classe : on ne garde que les sous-classes de CFHIRResource.
            $keep = static function ($class) {
                return is_subclass_of($class, CFHIRResource::class);
            };
        } else {
            // Filtre par champ : le champ doit être une référence non nulle de la ressource.
            $definition_fields = CFHIRDefinition::getFields($resource);
            $keep              = static function ($field) use ($definition_fields, $resource) {
                if (!in_array($field, $definition_fields)) {
                    return false;
                }

                /** @var CFHIRDataType $datatype */
                $datatype = $resource->{$field};

                return $datatype
                    && $datatype instanceof CFHIRDataTypeReference
                    && !$datatype->isNull();
            };
        }

        $filtered = array_filter($this->resource_retriever, $keep);

        $this->resource_retriever = count($filtered) > 0 ? $filtered : 'none';
    }

    /**
     * @param Envelope $envelope
     *
     * @return bool
     */
    private function supportRetrieveResources(Envelope $envelope): bool
    {
        $this->resolveTypeRetrieve($envelope);

        return $this->resource_retriever && $this->resource_retriever !== 'none';
    }

    /**
     * @param Envelope $envelope
     *
     * @return array
     * @throws InvalidArgumentException
     */
    private function searchReferences(Envelope $envelope): array
    {
        $resource  = $envelope->getResource();
        $class_map = new FHIRClassMap();

        $references = [];

        foreach ($this->getReferenceFields($resource) as $reference_field) {
            /** @var CFHIRDataTypeReference[]|CFHIRDataTypeReference $values */
            if (!property_exists($resource, $reference_field) || !($values = $resource->{$reference_field})) {
                continue;
            }

            if (!is_array($values)) {
                $values = [$values];
            }

            foreach ($values as $value) {
                if ($value->isNull()) {
                    continue;
                }

                // Résolution de la ressource cible pointée par la référence.
                $target = $value->resolveResourceTarget();
                if (!$target) {
                    continue;
                }

                if ($this->resource_retriever !== 'all') {
                    // Filtrage par type de ressource attendu.
                    $candidate_classes = array_map(
                        'get_class',
                        $class_map->resource->listResources($target::RESOURCE_TYPE)
                    );

                    if (count(array_diff($candidate_classes, $this->resource_retriever)) !== 1) {
                        continue;
                    }
                }

                if ($value->resolveUrl()) {
                    $references[] = $value;
                }
            }
        }

        return $references;
    }


    /**
     * @param CFHIRResource $resource
     *
     * @return array
     * @throws InvalidArgumentException
     */
    private function getReferenceFields(CFHIRResource $resource): array
    {
        // Selon le mode, on inspecte tous les champs de la ressource ou la liste demandée.
        $candidate_fields = $this->type_retriever === 'class'
            ? CFHIRDefinition::getFields($resource)
            : $this->resource_retriever;

        $reference_fields = [];

        foreach ($candidate_fields as $candidate) {
            $element_def   = CFHIRDefinition::getElementDefinition($resource, $candidate);
            $element_class = CMbArray::getRecursive($element_def, 'datatype class');

            if ($element_class === CFHIRDataTypeReference::class) {
                $reference_fields[] = $candidate;
            }
        }

        return $reference_fields;
    }

}
