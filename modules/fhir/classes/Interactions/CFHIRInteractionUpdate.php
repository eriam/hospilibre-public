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
use Ox\Interop\Fhir\Event\CFHIREvent;
use Ox\Interop\Fhir\Exception\CFHIRExceptionRequired;
use Ox\Interop\Fhir\Request\CFHIRRequest;
use Ox\Interop\Fhir\Resources\CFHIRResource;

/**
 * The update interaction creates a new current version for an existing resource or creates an initial version if no
 * resource already exists for the given id
 */
class CFHIRInteractionUpdate extends CFHIRInteraction
{
    /** @var string Interaction name */
    public const NAME = "update";

    /** @var string */
    public const METHOD = 'PUT';

    /**
     * Generate resource
     *
     * @param CFHIRResource $resource
     * @param CMbObject     $object object
     *
     * @return CFHIRRequest
     */
    public function build(CFHIRResource $resource, CMbObject $object): CFHIRRequest
    {
        $class_name = CFHIREvent::getEventClass($resource);

        /** @var CFHIREvent $event */
        $event            = new $class_name();
        $event->_receiver = $this->_receiver;

        $bundle = $event->build($object);

        return new CFHIRRequest($bundle);
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

    /**
     * @return string|null
     */
    public function getBasePath(): ?string
    {
        if (!$this->resource->id || !$this->resource->id->getValue()) {
            $interaction_name = $this::NAME;
            throw new CFHIRExceptionRequired("Element 'resource_id' is missing in interaction '$interaction_name'");
        }

        // TODO Gérer le cas d'un conditional update
        return $this->resourceType . '/' . $this->resource->id->getValue();
    }
}
