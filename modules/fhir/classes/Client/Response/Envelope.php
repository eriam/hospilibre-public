<?php

/**
 * @package Ox\Interop\Fhir\Client\Response
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Client\Response;

use Ox\Interop\Fhir\Resources\CFHIRResource;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Conteneur immuable transportant une reponse et les objets qui lui sont associes,
 * indexes par nom de classe. Les mutations renvoient une copie.
 *
 * @package Ox\Interop\Fhir\Client\Response
 */
class Envelope
{
    /** @var Response */
    private $response;

    /** @var array */
    private $data = [];

    /** @var CFHIRResource */
    private $resource;

    /**
     * @param CFHIRResource $resource
     * @param array         $objects
     */
    public function __construct(Response $response, array $objects = [])
    {
        $this->response = $response;

        foreach ($objects as $entry) {
            if (is_object($entry)) {
                $this->data[get_class($entry)][] = $entry;
            }
        }
    }

    /**
     * @return Response
     */
    public function getResponse(): Response
    {
        return $this->response;
    }

    /**
     * @return CFHIRResource
     */
    public function getResource(): ?CFHIRResource
    {
        return $this->resource;
    }

    /**
     * Dernier objet enregistre pour le type donne, ou null.
     *
     * @param string $type
     *
     * @return mixed|null
     */
    public function last(string $type)
    {
        $bucket = $this->data[$type] ?? [];

        if ($bucket === []) {
            return null;
        }

        return end($bucket) ?: null;
    }

    /**
     * Ajoute un objet et renvoie une nouvelle enveloppe.
     *
     * @param object $object
     *
     * @return Envelope
     */
    public function with(object $object): self
    {
        $copy = clone $this;

        if ($object instanceof CFHIRResource && !$this->resource) {
            $copy->resource = $object;

            return $copy;
        }

        $copy->data[get_class($object)][] = $object;

        return $copy;
    }

    /**
     * @param string $type
     *
     * @return $this
     */
    public function withoutAll(string $type): self
    {
        $copy = clone $this;
        unset($copy->data[$type]);

        return $copy;
    }


    /**
     * @param string $type
     *
     * @return $this
     */
    public function withoutType(string $type): self
    {
        $copy = clone $this;

        foreach (array_keys($copy->data) as $class) {
            if ($class === $type || is_subclass_of($class, $type)) {
                unset($copy->data[$class]);
            }
        }

        return $copy;
    }

    /**
     * @param string $type
     *
     * @return array
     */
    public function all(string $type): array
    {
        return $this->data[$type] ?? [];
    }
}
