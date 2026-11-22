<?php
/**
 * @package Hospilibre\Fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Interactions;

use Ox\Core\CMbArray;
use Ox\Core\CMbObject;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeId;
use Ox\Interop\Fhir\Event\CFHIREvent;
use Ox\Interop\Fhir\Request\CFHIRRequest;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Ox\Interop\Fhir\Response\CFHIRResponse;
use Ox\Interop\Fhir\Serializers\CFHIRParser;
use Throwable;

/**
 * The create interaction creates a new resource in a server-assigned location
 */
class CFHIRInteractionCreate extends CFHIRInteraction
{
    /** @var string Interaction name */
    public const NAME = "create";

    /** @var string */
    public const METHOD = 'POST';

    /**
     * @param CFHIRResource $resource
     * @param CFHIRParser   $result
     *
     * @return CFHIRResponse
     */
    public function handleResult(CFHIRResource $resource, $result): CFHIRResponse
    {
        // force to set actor and interactions
        $parsed_resource = $resource->buildFrom($result->getResource());

        // integrate data received
        $resource_stored = $resource->handle($parsed_resource);

        // force to set actor and interactions
        $resource_stored = $resource->buildFrom($resource_stored);

        // object stored
        $object = $resource_stored->getObject();
        try {
            if (($delegated_mapper = $resource_stored->getDelegatedMapper()) && $object) {
                $resource_stored->setMapper($delegated_mapper);
                $resource_stored->mapFrom($object);
            }
        } catch (Throwable $exception) {
            // force id in resource_stored
            if ($object) {
                $resource_stored->id = new CFHIRDataTypeId($object->getUuid());
            }
        }

        $this->setResource($resource_stored);

        return new CFHIRResponse($this, $this->format);
    }

    /**
     * Generate resource
     *
     * @param CFHIRResource $resource
     * @param CMbObject     $object object
     *
     * @return CFHIRRequest
     * @deprecated
     */
    public function build(CFHIRResource $resource, CMbObject $object): CFHIRRequest
    {
        $class_name = CFHIREvent::getEventClass($resource);

        /** @var CFHIREvent $event */
        $event            = new $class_name();
        $event->_receiver = $this->_receiver;

        $resource = $event->build($object);

        return new CFHIRRequest($resource);
    }

    /**
     * Create resource on this with is resource type name
     *
     * @return string|null
     */
    public function getBasePath(): ?string
    {
        return $this->resourceType;
    }

    /**
     * @param array|null|string $data
     *
     * @return string|null
     */
    public function getBody($data): ?string
    {
        if (is_string($data) || !$data) {
            return $data ?: null;
        }

        return CMbArray::get($data, 0);
    }
}
