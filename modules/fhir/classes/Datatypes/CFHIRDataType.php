<?php

/**
 * @package Hospilibre\Fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Datatypes;

use DOMDocument;
use DOMElement;
use DOMException;
use Exception;
use Ox\Core\Autoload\IShortNameAutoloadable;
use Ox\Core\Cache;
use Ox\Core\CClassMap;
use Ox\Core\CMbArray;
use Ox\Interop\Fhir\CFHIRXPath;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeComplex;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeExtension;
use Ox\Interop\Fhir\Exception\CFHIRException;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Ox\Interop\Fhir\Resources\R4\CFHIRDefinition;
use Ox\Interop\Fhir\Serializers\CFHIRParser;
use Ox\Interop\Fhir\Utilities\CFHIRTools;
use Psr\SimpleCache\InvalidArgumentException;
use ReflectionClass;
use ReflectionException;

/**
 * FHIR data type
 */
class CFHIRDataType implements IShortNameAutoloadable
{
    /** @var string */
    public const NAME = 'Element';

    /** @var CFHIRDataTypeString */
    public $id;

    /** @var CFHIRDataTypeExtension[] */
    public $extension = [];

    /** @var array */
    protected $definition;

    /** @var mixed */
    protected $_value;

    /** @var CFHIRResource|CFHIRDataType|null */
    protected $_parent;

    /** @var CFHIRResource|null */
    protected $_parent_resource;

    /**
     * CFHIRDataType constructor.
     *
     * @param mixed|null $value
     */
    public function __construct($value = null)
    {
        $this->setValue($value);
    }

    /**
     * @return mixed
     */
    public function getValue()
    {
        return $this->_value;
    }

    /**
     * @param mixed $value
     */
    public function setValue($value): void
    {
        $this->_value = $value;
    }

    /**
     * Output to DOM XML
     *
     * @param DOMElement $node DOM element to append the data to
     *
     * @return null
     * @throws DOMException
     */
    public function toXML(DOMElement $node, string $field, DOMDocument $document): void
    {
        $element = $document->createElementNS(CFHIRXPath::FHIR_NAMESPACE, $field);
        $element->setAttribute('value', $this->getValue());

        // id && extension
        $this->extensionToXml($element, $document);

        $node->appendChild($element);
    }

    /**
     * @param string $field
     *
     * @return array|null
     */
    public function toJSON(string $field): ?array
    {
        if ($this->isNull()) {
            return null;
        }

        $data = [];
        // value
        $data[$field] = $this->getValue();

        // id
        $extension_key = "_$field";
        if ($this->id && !$this->id->isNull()) {
            $data[$extension_key]['id'] = $this->id->getValue();
        }

        // manage Extension
        return $this->extensionToJson($data, $extension_key);
    }

    /**
     * @param array  $data
     * @param string $field
     *
     * @return array|null
     */
    protected function extensionToJson(array $data, string $field): ?array
    {
        // extension
        if ($this->extension && is_array($this->extension)) {
            $data[$field]['extension'] = CFHIRTools::manageDatatypeJSONArray($this->extension, $field)[$field] ?? [];
        }

        return $data;
    }

    /**
     * @param DOMElement  $element
     * @param DOMDocument $document
     *
     * @throws DOMException
     */
    protected function extensionToXml(DOMElement $element, DOMDocument $document): void
    {
        if ($this->id && !$this->id->isNull()) {
            $element->setAttribute('id', $this->id->getValue());
        }

        if ($this->extension) {
            foreach ($this->extension as $extension) {
                $extension->toXML($element, 'extension', $document);
            }
        }
    }

    /**
     * @param DOMElement  $element
     * @param CFHIRXPath  $xpath
     * @param CFHIRParser $parser
     *
     * @throws InvalidArgumentException
     */
    public function fromXML(DOMElement $element, CFHIRXPath $xpath, CFHIRParser $parser): void
    {
        // value
        if (($attribute = $element->getAttribute('value')) !== null) {
            $this->setValue($attribute);
        }

        // id
        if (!empty(($id = $element->getAttribute('id')))) {
            $this->id = new CFHIRDataTypeString($id);
            $this->id->_parent = $this;
            $this->id->_parent_resource = &$this->_parent_resource;
        }

        // extension
        $extensions = $xpath->query('fhir:extension', $element);
        /** @var DOMElement $extension */
        foreach ($extensions as $extension) {
            $datatype_extension = new CFHIRDataTypeExtension();
            $datatype_extension->_parent = $this;
            $datatype_extension->_parent_resource = &$this->_parent_resource;
            $datatype_extension->fromXML($extension, $xpath, $parser);

            $this->extension[] = $datatype_extension;
        }
    }

    /**
     * @return array
     * @throws InvalidArgumentException
     */
    public function getDefinition(): array
    {
        if (!$this->definition) {
            $def              = CFHIRDefinition::getDefinition(get_class($this));
            $this->definition = $def && $def['elements'] ? $def['elements'] : [];
        }

        return $this->definition;
    }

    /**
     * Know if datatype has a value
     *
     * @return bool
     */
    public function isNull(): bool
    {
        return $this->getValue() === null && !$this->extension;
    }

    /**
     * @param string $type
     * @param bool   $instance
     *
     * @return static|string
     * @throws Exception|CFHIRException
     */
    public static function get(string $type, bool $instance = true)
    {
        $class_map = CClassMap::getInstance();
        $datatypes = $class_map->getClassChildren(CFHIRDataType::class);
        $type      = strtolower($type);

        /** @var CFHIRDataType $datatype */
        foreach ($datatypes as $datatype) {
            if ($type === strtolower($datatype::NAME)) {
                return $instance ? new $datatype() : $datatype;
            }
        }

        throw new CFHIRException("The type $type is not a valid datatype or is not supported");
    }

    /**
     * @param string $key
     *
     * @return string
     * @throws ReflectionException|InvalidArgumentException
     */
    public function getDataTypeElement(string $key): string
    {
        $default = CFHIRDataTypeString::class;
        if (!$this instanceof CFHIRDataTypeComplex) {
            return $default;
        }

        $class = get_class($this);

        return CMbArray::getRecursive($this->getMappingComplexType(), "$class $key class", $default);
    }

    /**
     * @return array
     * @throws Exception
     */
    public function getPrimitiveTypes(): array
    {
        $reflection_complex_type = new ReflectionClass(CFHIRDataTypeComplex::class);
        $classes                 = CClassMap::getInstance()->getClassChildren(CFHIRDataType::class, false, true);

        return array_filter(
            $classes,
            function ($class) use ($reflection_complex_type) {
                return !str_starts_with($class, $reflection_complex_type->getNamespaceName());
            }
        );
    }

    /**
     * @param string $key
     *
     * @return bool
     * @throws ReflectionException|InvalidArgumentException
     */
    public function isDataTypeElementIsArray(string $key): bool
    {
        if (!$this instanceof CFHIRDataTypeComplex) {
            return false;
        }

        $class = get_class($this);

        return CMbArray::getRecursive($this->getMappingComplexType(), "$class $key is_array", false);
    }


    /**
     * @param string|null $datatype_class
     *
     * @return array
     * @throws ReflectionException|InvalidArgumentException
     */
    private function getMappingComplexType(?string $datatype_class = null): array
    {
        $cache   = new Cache('FHIR', 'mapping_datatype', Cache::INNER_OUTER);
        $mapping = $cache->get();
        if (!$mapping) {
            $mapping = $this->generateCache($cache);
        }

        if ($datatype_class) {
            return CMbArray::get($mapping, $datatype_class, []);
        }

        return $mapping;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function resetCacheMappingComplexType(): void
    {
        $cache = new Cache('FHIR', 'mapping_datatype', Cache::INNER_OUTER);
        $cache->rem();
    }

    /**
     * @param Cache $cache
     *
     * @return array
     * @throws ReflectionException|InvalidArgumentException|CFHIRException
     * @throws Exception
     */
    public function generateCache(Cache $cache): array
    {
        $class_map                 = CClassMap::getInstance();
        $complex_datatype_elements = $class_map->getClassChildren(CFHIRDataTypeComplex::class, false, true);
        $elements                  = [];
        foreach ($complex_datatype_elements as $datatype_class) {
            $reflexion = new ReflectionClass($datatype_class);

            foreach ($reflexion->getProperties() as $property) {
                if (!$property->isPublic() || $property->isStatic()) {
                    continue;
                }

                $doc_comment   = $property->getDocComment();
                $property_name = $property->getName();
                preg_match("/@var (?'class'[\w|\[\]]+)/", $doc_comment, $match);

                // check type is declared for property
                if (!$class = CMbArray::get($match, 'class')) {
                    throw new CFHIRException(
                        "The type for property '$property_name' is not declared in '$datatype_class'"
                    );
                }

                if ($is_array = str_ends_with($class, '[]')) {
                    $class = substr($class, 0, strlen($class) - 2);
                }

                $object_datatype = CClassMap::getSN($class);

                $elements[$reflexion->getName()][$property_name] = [
                    'class'    => $object_datatype,
                    'is_array' => $is_array,
                ];
            }
        }

        return $cache->put($elements, true);
    }

    /**
     * @return bool
     */
    public function isSummary(): bool
    {
        return $this->_parent_resource && $this->_parent_resource->isSummary();
    }

    /**
     * @param CFHIRResource|null $resource
     */
    public function setParentResource(?CFHIRResource $resource): void
    {
        $this->_parent_resource = $resource;
    }

    /**
     * @param CFHIRDataType|CFHIRResource|null $parent
     */
    public function setParent($parent): void
    {
        $is_not_fhir_object = (!$parent instanceof CFHIRResource && !$parent instanceof CFHIRDataType);
        if (!$parent || !is_object($parent) || $is_not_fhir_object) {
            return;
        }

        $this->_parent = $parent;
    }

    /**
     * @return CFHIRResource|null
     */
    public function getParentResource(): ?CFHIRResource
    {
        return $this->_parent_resource;
    }

    /**
     * @return CFHIRDataType|CFHIRResource|null
     */
    public function getParent()
    {
        return $this->_parent;
    }
}
