<?php

/**
 * @package Hospilibre\Fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Resources;

use Exception;
use Ox\Core\Api\Exceptions\ApiRequestException;
use Ox\Core\CAppUI;
use Ox\Core\CMbArray;
use Ox\Core\CMbDT;
use Ox\Core\CMbObject;
use Ox\Core\CMbSecurity;
use Ox\Core\CMbString;
use Ox\Core\CStoredObject;
use Ox\Interop\Dmp\CDMPTools;
use Ox\Interop\Eai\CInteropActor;
use Ox\Interop\Eai\CInteropReceiver;
use Ox\Interop\Eai\CInteropSender;
use Ox\Interop\Fhir\Actors\CReceiverFHIR;
use Ox\Interop\Fhir\Actors\CSenderFHIR;
use Ox\Interop\Fhir\Actors\IActorFHIR;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectHandleInterface;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectMapperInterface;
use Ox\Interop\Fhir\Contracts\Resources\ResourceInterface;
use Ox\Interop\Fhir\Controllers\CFHIRController;
use Ox\Interop\Fhir\Datatypes\CFHIRDataType;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeId;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeInstant;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeUri;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeAddress;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCoding;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeExtension;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeIdentifier;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeMeta;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;
use Ox\Interop\Fhir\Event\CFHIREvent;
use Ox\Interop\Fhir\Exception\CFHIRException;
use Ox\Interop\Fhir\Exception\CFHIRExceptionForbidden;
use Ox\Interop\Fhir\Exception\CFHIRExceptionNotFound;
use Ox\Interop\Fhir\Interactions\CFHIRInteraction;
use Ox\Interop\Fhir\Profiles\CFHIR;
use Ox\Interop\Fhir\Resources\R4\CFHIRDefinition;
use Ox\Interop\Fhir\Response\CFHIRResponse;
use Ox\Interop\Fhir\Serializers\CFHIRParser;
use Ox\Interop\Fhir\Serializers\CFHIRSerializer;
use Ox\Interop\Fhir\Utilities\CCapabilitiesResource;
use Ox\Interop\Fhir\Utilities\SearchParameters\SearchParameter;
use Ox\Interop\Fhir\Utilities\SearchParameters\SearchParameterBool;
use Ox\Interop\Fhir\Utilities\SearchParameters\SearchParameterNumber;
use Ox\Interop\Fhir\Utilities\SearchParameters\SearchParameterString;
use Ox\Interop\InteropResources\valueset\CANSValueSet;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Patients\CMedecin;
use Hospilibre\Sante400\CIdSante400;
use Hospilibre\System\CSenderHTTP;
use Hospilibre\System\CUserLog;
use phpDocumentor\Reflection\Types\Boolean;
use Psr\SimpleCache\InvalidArgumentException;
use ReflectionException;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * FHIR generic resource
 */
class CFHIRResource implements ResourceInterface
{
    // constants
    /** @var string[] */
    public const FHIR_RESOURCE_AVAILABLE = [
        'R4' => self::FHIR_VERSION_R4,
    ];

    /** @var string */
    public const FHIR_VERSION_R4 = '4.0';

    /** @var string */
    public const RESOURCE_TYPE = '';

    /** @var string */
    public const PROFILE_TYPE = '';

    /** @var CFHIR */
    public const PROFILE_CLASS = CFHIR::class;

    /** @var string */
    public const VERSION_NORMATIVE = '';

    /** @var string[] */
    public const FORCE_AVAILABLE_FHIR_VERSIONS = [];

    /** @var string */
    public const RESOURCE_CONTEXT_PROFILING = '';

    /** @var bool */
    protected const ACCEPT_VERSION_ID = false;

    /** @var int */
    private const FHIR_VERSION_PART = 4;

    // Resource attributes
    /** @var CFHIRDataTypeId */
    public $id;

    /** @var CFHIRDataTypeMeta */
    public $meta;

    /** @var CFHIRDataTypeUri */
    public $implicitRules;

    /** @var CFHIRDataTypeCode */
    public $language;

    // Object attributes
    /** @var CSenderHTTP */
    public $_sender;

    /** @var CReceiverFHIR */
    public $_receiver;

    /** @var CFHIRInteraction */
    protected $interaction;

    /** @var CStoredObject */
    protected $object;

    /** @var CCapabilitiesResource */
    protected $capabilities;

    /** @var array<ParameterBag, ParameterBag> */
    public $parameters;

    /** @var bool */
    protected $summary = false;

    /** @var DelegatedObjectMapperInterface */
    protected $object_mapping;

    /** @var DelegatedObjectHandleInterface */
    protected $object_handle;

    /** @var bool */
    protected $is_contained = false;

    /**
     * @param bool $pretty
     *
     * @return string
     * @throws InvalidArgumentException
     */
    public function toXML(bool $pretty = false): string
    {
        $serializer = CFHIRSerializer::serialize($this, 'xml', ['pretty' => $pretty]);

        return $serializer->getResourceSerialized();
    }

    /**
     * @param bool $pretty
     *
     * @return string
     * @throws InvalidArgumentException
     */
    public function toJSON(bool $pretty = false): string
    {
        $serializer = CFHIRSerializer::serialize($this, 'json', ['pretty' => $pretty]);

        return $serializer->getResourceSerialized();
    }

    /**
     * Get resource type
     *
     * @return string
     */
    public function getResourceType(): string
    {
        return $this::RESOURCE_TYPE;
    }

    /**
     * @return string
     */
    public static function getCanonical(): string
    {
        $end = static::PROFILE_TYPE ?: static::RESOURCE_TYPE;

        return trim((static::PROFILE_CLASS)::BASE_PROFILE, '/') . "/" . $end;
    }

    /**
     * @param CFHIRInteraction $interaction
     */
    public function setInteraction(?CFHIRInteraction $interaction): void
    {
        $this->interaction = $interaction;
    }

    /**
     * @return CFHIRInteraction|null
     */
    public function getInteraction(): ?CFHIRInteraction
    {
        return $this->interaction;
    }

    /**
     * @return string[]
     */
    public function getAvailableFHIRVersions(): array
    {
        $versions           = [];
        $normative_version  = $this::VERSION_NORMATIVE;
        $available_versions = $this::FHIR_RESOURCE_AVAILABLE;
        $resource           = $this->getFhirParentResource();
        $base_fhir_version  = $resource->getFHIRVersion();

        foreach ($available_versions as $version) {
            $add = $normative_version && $version >= $normative_version;
            $add = $add || $version === $base_fhir_version;
            $add = $add || in_array($version, $this::FORCE_AVAILABLE_FHIR_VERSIONS);
            if ($add) {
                $versions[] = $version;
            }
        }

        return $versions;
    }

    /**
     * @return $this
     */
    public function getFhirParentResource(): self
    {
        if ($this->isFHIRResource()) {
            return $this;
        }

        $exception = new CFHIRException(
            'The class "' . get_class($this) . '" should be has a parent declared like FHIR resource.'
        );

        foreach (class_parents($this) as $class) {
            if ($class === CFHIRResource::class) {
                throw $exception;
            }

            /** @var CFHIRResource $resource */
            $resource = new $class();
            if ($resource->isFHIRResource()) {
                return $resource;
            }
        }

        throw $exception;
    }

    /**
     * @return bool
     */
    public function isFHIRResource(): bool
    {
        return str_starts_with($this->getCapabilities()->getProfile(), CFHIR::BASE_PROFILE);
    }

    /**
     * @return bool
     */
    public function isProfileResource(): bool
    {
        return !$this->isFHIRResource();
    }

    /**
     * @return CCapabilitiesResource
     */
    public function getCapabilities(): CCapabilitiesResource
    {
        if ($this->capabilities) {
            return $this->capabilities;
        }

        return $this->capabilities = $this->generateCapabilities();
    }

    /**
     * @return string
     */
    public function getProfile(): string
    {
        return $this->getCapabilities()->getProfile();
    }

    /**
     * @param CCapabilitiesResource $capabilities
     */
    public function setCapabilities(CCapabilitiesResource $capabilities): void
    {
        $this->capabilities = $capabilities;
    }

    /**
     * @return CCapabilitiesResource
     */
    protected function generateCapabilities(): CCapabilitiesResource
    {
        $version_part = explode('\\', get_class($this))[self::FHIR_VERSION_PART];
        $fhir_version = CMbArray::get(self::FHIR_RESOURCE_AVAILABLE, $version_part);

        return $this->capabilities = (new CCapabilitiesResource())
            ->setType($this::RESOURCE_TYPE)
            ->setProfile(($this::PROFILE_CLASS)::BASE_PROFILE . ($this::PROFILE_TYPE ?: $this::RESOURCE_TYPE))
            ->setVersion($fhir_version)
            ->addSearchAttributes(
                [
                    new SearchParameterNumber('_id'),
                    new SearchParameterNumber('_count'),
                    new SearchParameterNumber('_offset'),
                    new SearchParameterString('_profile'),
                    new SearchParameterString('_include'),
                    new SearchParameterString('_type'),
                    new SearchParameterString('_format'),
                    new SearchParameterString('_summary'),
                    new SearchParameterBool('_pretty'),
                ]
            );
    }

    /**
     * @param DelegatedObjectMapperInterface $object_mapper
     *
     * @return $this
     */
    public function setMapper(DelegatedObjectMapperInterface $object_mapper): self
    {
        $this->object_mapping = $object_mapper;

        return $this;
    }

    /**
     * @param CStoredObject $object
     *
     * @deprecated use system with configuration
     *
     * @return DelegatedObjectMapperInterface|null
     */
    protected function setMapperOld(CStoredObject $object): ?DelegatedObjectMapperInterface
    {
        return null;
    }

    /**
     * Return the fhir version of resource
     *
     * @return string
     */
    public function getFHIRVersion(): string
    {
        $resource = $this->getFhirParentResource();

        return $resource->getResourceVersion();
    }

    /**
     * Return the version of resource or profile
     *
     * @return string
     */
    public function getResourceVersion(): string
    {
        return $this->getCapabilities()->getVersion();
    }

    /**
     * Get resource Id
     *
     * @return string
     */
    public function getResourceId(): ?string
    {
        if (!$this->id) {
            return null;
        }

        if (!$this->id instanceof CFHIRDataTypeId) {
            return null;
        }

        return $this->id->getValue();
    }

    /**
     * @param CInteropActor|null $actor
     */
    public function setInteropActor(?CInteropActor $actor): void
    {
        if ($actor instanceof CInteropReceiver) {
            $this->_receiver = $actor;
        } elseif ($actor instanceof CInteropSender) {
            $this->_sender = $actor;
        }
    }

    /**
     * @return  CInteropActor|null|IActorFHIR $actor
     */
    public function getInteropActor(): ?CInteropActor
    {
        return $this->_receiver ?: $this->_sender;
    }

    /**
     * Process data
     *
     * @param CFHIRInteraction  $interaction
     * @param array|string|null $data Data
     *
     * @return CFHIRResponse
     * @throws CFHIRException
     *
     */
    public function process(CFHIRInteraction $interaction, $data = null): CFHIRResponse
    {
        $this->interaction = $interaction;
        $this->interaction->setResource($this);

        $method = $this->interaction->getResourceMethodName();
        if (!method_exists($this, $method)) {
            $name = $interaction::NAME;
            throw new CFHIRException("Unknown interaction type: '$name'", 404);
        }

        $result = $this->$method($data);

        return $this->interaction->handleResult($this, $result);
    }

    /**
     * @param array|string|null $data
     * @param string|null       $format
     *
     * @return CFHIRParser
     * @throws InvalidArgumentException
     */
    public function interactionCreate($data, ?string $format = null): CFHIRParser
    {
        return CFHIRParser::parse($data, $format);
    }

    /**
     * @return $this
     */
    public function buildSelf(): self
    {
        /** @var CFHIRResource $resource */
        return $this->buildFrom(new $this());
    }

    /**
     * @param CFHIRResource|null $resource
     *
     * @return mixed|CFHIRResource|null
     */
    public function buildFrom(CFHIRResource $resource = null): CFHIRResource
    {
        if (!$resource) {
            $resource = new $this();
        }

        $resource->parameters        = $this->parameters;
        $resource->_sender           = $this->_sender;
        $resource->_receiver         = $this->_receiver;
        $resource->interaction       = $this->interaction;

        return $resource;
    }

    /**
     * @param CStoredObject $object
     */
    public function setObject(CStoredObject $object): void
    {
        $this->object = $object;
    }

    /**
     * @param CStoredObject $object
     *
     * @throws InvalidArgumentException
     */
    public function mapFrom(CStoredObject $object): self
    {
        $this->object = $object;

        if ($this->object_mapping = $this->getDelegatedMapper()) {
            $this->object_mapping->setResource($this, $object);
            $this->object_mapping->initialize();
        }

//        if (!$this->object_mapping) {
//            throw new CFHIRException(CAppUI::tr("ResourceInterface-msg-empty for mapping"));
//        }

        $this->map($object);

        // link datatype with resource ($this)
        foreach (CFHIRDefinition::getFields($this) as $field) {
            /** @var CFHIRDataType $field */
            if (!$fields = $this->{$field}) {
                continue;
            }

            if (!is_array($fields)) {
                $fields = [$fields];
            }

            /** @var CFHIRDataType $_field */
            foreach ($fields as $_field) {
                $_field->setParentResource($this);
                $_field->setParent($this);
            }
        }

        return $this;
    }

    /**
     * Try to find a mapper from this resource
     *
     * @return DelegatedObjectMapperInterface|null
     * @throws Exception
     */
    public function getDelegatedMapper(): ?DelegatedObjectMapperInterface
    {
        if ($this->object_mapping) {
            return $this->object_mapping;
        }

        // old system, don't use it now, it will be deleted
        if ($this->object && ($object_mapping = $this->setMapperOld($this->object))) {
            return $object_mapping;
        }

        // try to find it from actor
        if ($actor = $this->getInteropActor()) {
            if (!$actor instanceof IActorFHIR) {
                $actor = new CSenderFHIR($actor);
            }

            if ($object_mapping = $actor->getDelegatedMapper($this)) {
                return $object_mapping;
            }
        }

        return null;
    }

    /**
     * Get delegated object handle
     *
     * @return DelegatedObjectHandleInterface|null
     * @throws Exception
     */
    public function getObjectHandle(): ?DelegatedObjectHandleInterface
    {
        if ($this->object_handle) {
            return $this->object_handle;
        }

        // try to find it from actor
        if ($actor = $this->getInteropActor()) {
            if (!$actor instanceof IActorFHIR) {
                $actor = new CSenderFHIR($actor);
            }

            if ($object_handle = $actor->getDelegatedHandle($this)) {
                return $object_handle;
            }
        }

        return null;
    }

    /**
     * Handle object in function of handle configured
     *
     * @param CFHIRResource|null $resource
     *
     * @return $this
     * @throws Exception
     */
    public function handle(?CFHIRResource $resource = null): ?CFHIRResource
    {
        if (!$resource) {
            $resource = $this;
        }

        if (!$delegated_handle = $this->getObjectHandle()) {
            throw new CFHIRExceptionNotFound('DelegatedObjectHandleInterface-msg-configuration none object');
        }

        return $delegated_handle->handle($resource);
    }

    /**
     * Map the resource from a CMbObject
     *
     * @param CStoredObject $object The object with the data to get
     *
     * @return void
     * @throws Exception
     */
    protected function map(CStoredObject $object): void
    {
        // summary resource
        $summary = $this->getParameter('_summary');
        if ($summary && $summary->getValue() === 'true') {
            $this->summary = true;
        }

        // id
        $this->mapId();

        // meta
        $this->mapMeta();

        // language
        $this->mapLanguage();
    }

    /**
     * Map property id
     */
    protected function mapId(): void
    {
        if ($this->object->_id) {
            $this->id = new CFHIRDataTypeId($this->getInternalId($this->object));
        }
    }

    /**
     * Map property meta
     */
    protected function mapMeta(): void
    {
        $last_log = $this->object->loadLastLog();

        // meta
        $this->meta = CFHIRDataTypeMeta::build([]);

        // meta / versionID|lastUpdated
        if (!$this->isContained()) {
            if ($this->object && $this->object->_id) {
                $this->meta->versionId   = new CFHIRDataTypeId($last_log->_id);
                $this->meta->lastUpdated = new CFHIRDataTypeInstant($last_log->date);
            } else {
                $this->meta->lastUpdated = new CFHIRDataTypeInstant(CMbDT::dateTime());
            }
        }

        // meta / profile
        if (!$this->isFHIRResource()) {
            $profiles            = [new CFHIRDataTypeString($this->getProfile())];
            $this->meta->profile = array_merge($this->meta->profile ?: [], $profiles);
        }

        // meta / source
        $profile_class = $this::PROFILE_CLASS;
        if ($source = $profile_class::RESOURCE_META_SOURCE) {
            $this->meta->source = new CFHIRDataTypeUri($source);
        }
    }

    /**
     * Map property language (only fr-FR => French (France) is supported)
     */
    protected function mapLanguage(): void
    {
        $this->language = new CFHIRDataTypeCode('fr-FR');
    }

    /**
     * Perform a history query based on the current object data
     *
     * @return CStoredObject|null
     * @throws CFHIRExceptionNotFound|Exception
     */
    public function interactionHistoryInstance(): ?CStoredObject
    {
        $object = $this->getObject();
        if (!$object->_history) {
            $object->loadHistory();
        }

        return $this->outWithPerm($object);
    }

    /**
     * @return CStoredObject
     */
    public function getObject(): CStoredObject
    {
        if ($this->object && $this->object->_id) {
            return $this->object;
        }

        $class = $this->getClass();

        return new $class();
    }

    /**
     * Get the associated MbClass
     * // todo il faut supprimer cette notion de class par resource
     * @return string
     */
    public function getClass(): ?string
    {
        return null;
    }

    /**
     * @param CStoredObject $object
     *
     * @return CStoredObject
     */
    protected function outWithPerm(CStoredObject $object): ?CStoredObject
    {
        if (!$object || !$object->getPerm(PERM_READ || !$object->_id)) {
            return null;
        }

        return $object;
    }

    /**
     * Build Resource
     *
     * @param CStoredObject $object object
     * @param CFHIREvent    $event  FHIR event
     *
     * @return void
     * @throws CFHIRException
     */
    public function build(CStoredObject $object, CFHIREvent $event): void
    {
        $this->_receiver = $event->_receiver;

        if (!$this->_receiver || !$this->_receiver->_id) {
            throw new CFHIRException("Object receiver not present");
        }

        if (!$this->_receiver->_tag_fhir) {
            throw new CFHIRException("Tag FHIR not defined on receiver");
        }
    }

    /**
     * Set masterIdentifier field on resource
     *
     * @param string $identifier identifier
     * @param string $system     system
     *
     * @return CFHIRDataTypeIdentifier
     */
    public function addMasterIdentifier($identifier, $system = null)
    {
        return CFHIRDataTypeIdentifier::build(
            [
                "system" => $system,
                "value"  => $identifier,
            ]
        );
    }

    /**
     * Format codeable concept fhir type
     *
     * @param array $data data
     *
     * @return array
     */
    public function formatCodeableConcept($data)
    {
        return [
            "coding" => [
                CFHIRDataTypeCoding::build(
                    [
                        "system"  => new CFHIRDataTypeString(CMbArray::get($data, "codeSystem")),
                        "code"    => new CFHIRDataTypeString(CMbArray::get($data, "code")),
                        "display" => new CFHIRDataTypeString(CMbArray::get($data, "displayName")),
                    ]
                ),
            ],
        ];
    }

    /**
     * Build subject field on resource
     *
     * @param CMbObject $object object
     *
     * @return CFHIRDataTypeReference
     * @throws CFHIRException
     */
    function addSubject(CMbObject $object)
    {
        $resource_name = null;
        switch ($object->_class) {
            case "CPatient":
                $resource_name = "Patient";
                break;
            case "CSejour":
                $resource_name = "Encounter";
                break;
            case "CMediusers":
                $resource_name = "Practitioner";
                break;
            default;
        }

        return CFHIRDataTypeReference::build(
            [
                "reference" => new CFHIRDataTypeString(
                    CFHIRController::getUrl(
                        "fhir_read",
                        [
                            'resource'    => $resource_name,
                            'resource_id' => $object->_id,
                        ]
                    )
                ),
                // TODO XDS TOOLKIT
                //"reference" => new CFHIRDataTypeString("http://localhost:8080/xds_toolkit_7.2.1/fsim/default__fhir_support/fhir/Patient/4e7a2153-6659-47d4-b0f6-82616a81b806"),
            ]
        );
    }

    /**
     * @param array|CFHIRDataTypeCoding[]|CFHIRDataTypeCoding $codingData
     * @param string|null                                     $text
     * @param array                                           $reference
     *
     * @return CFHIRDataTypeCodeableConcept[]|CFHIRDataTypeCodeableConcept
     * @deprecated use instead CFHIRDataTypeCodeableConcept::addCodeable
     */
    protected function addCodeableConcepts($codingData, ?string $text = null, ?array $reference = null)
    {
        if (!is_array($codingData)) {
            $codingData = [$codingData];
        }

        $codingRefs = [];
        foreach ($codingData as $key => $coding) {
            if (is_object($coding) && $coding instanceof CFHIRDataTypeCoding) {
                $codingRefs[] = $coding;
                continue;
            }
            $system  = CMbArray::get($coding, 'system', '');
            $code    = CMbArray::get($coding, 'code', '');
            $display = CMbArray::get($coding, 'display', '');

            $codingRefs[] = $this->addCoding($system, $code, $display);
        }

        $data = ['coding' => $codingRefs];
        if ($text) {
            $data['text'] = new CFHIRDataTypeString($text);
        }

        if ($reference === null) {
            return CFHIRDataTypeCodeableConcept::build($data);
        }

        return array_merge($reference, [CFHIRDataTypeCodeableConcept::build($data)]);
    }

    /**
     * @param string     $system
     * @param string     $code
     * @param string     $displayName
     * @param array|null $reference
     *
     * @return CFHIRDataTypeCoding[] | CFHIRDataTypeCoding
     * @deprecated use instead CFHIRDataTypeCoding::addCoding()
     */
    protected function addCoding(string $system, string $code, string $displayName, ?array $reference = null)
    {
        $data = [
            "system"  => new CFHIRDataTypeString($system),
            "code"    => new CFHIRDataTypeCode($code),
            "display" => new CFHIRDataTypeString($displayName),
        ];

        if ($reference === null) {
            return CFHIRDataTypeCoding::build($data);
        }

        return array_merge($reference, [CFHIRDataTypeCoding::build($data)]);
    }

    /**
     * @param CMediusers $mediusers
     *
     * @return CFHIRDataTypeCoding[]
     */
    public function setSpecialty(CMediusers $mediusers): array
    {
        // todo fonction FR
        $spec = $mediusers->loadRefOtherSpec();

        if (!$spec->libelle) {
            return [];
        }

        $subject_role = CMbArray::get(CDMPTools::$subjectRole, $mediusers->_user_type);
        $entry        = CANSValueSet::loadEntries("subjectRole", $subject_role);
        if (CMbArray::get($entry, 'code') !== '10' && CMbArray::get($entry, 'code') !== '21') {
            return [];
        }

        $code   = $spec->code;
        $code   = substr($code, strpos($code, "/") + 1);
        $entry  = CANSValueSet::loadEntries("subjectRole", $code);
        $values = [
            'codeSystem'  => 'https://mos.esante.gouv.fr/NOS/TRE_R38-SpecialiteOrdinale/FHIR/TRE-R38-SpecialiteOrdinale',
            'code'        => CMbArray::get($entry, 'code'),
            'displayName' => CMbString::removeAccents(CMbArray::get($entry, 'displayName')),
        ];

        return [$this->addTypeCodeCoding($values)];
    }

    /**
     * Build type field on resource
     *
     * @param array $values values
     *
     * @return CFHIRDataTypeCoding
     */
    function addTypeCodeCoding($values)
    {
        return CFHIRDataTypeCoding::build(
            $this->formatCodeableConcept($values)
        );
    }

    /**
     * @param string $url
     * @param array  $data
     *
     * @return array
     */
    public function addExtensions(array $items): array
    {
        $extensions = [];
        foreach ($items as $item) {
            $extensions[] = $this->addExtension($item);
        }

        return $extensions;
    }

    /**
     * @param string $url
     * @param array  $data
     *
     * @return array
     * @deprecated  use CFHIRDataTypeExtension::addExtension
     */
    public function addExtension(array $item): CFHIRDataTypeExtension
    {
        return CFHIRDataTypeExtension::build($item);
    }

    /**
     * @param string $url
     * @param array  $data
     *
     * @return array
     * @deprecated  use CFHIRDataTypeExtension::addExtension
     */
    public function formatExtension(string $url, array $data): array
    {
        return array_merge(["url" => $url], $data);
    }

    /**
     * Perform a search query based on the current object data
     *
     * @param mixed $data Data to interact with
     *
     * @return CStoredObject
     * @throws CFHIRExceptionNotFound
     */
    public function interactionRead(?array $data): ?CStoredObject
    {
        return $this->outWithPerm($this->getObject());
    }

    /**
     * Perform a search query based on the current object data
     *
     * @param array       $data Data to handle
     * @param string|null $format
     *
     * @return CStoredObject[]
     * @throws CFHIRExceptionNotFound
     */
    public function interactionSearchType(?array $data, ?string $format = null): array
    {
        $object = $this->getObject();
        // offset
        $offset = $this->getOffset();

        // limit
        $limit = $this->getLimit($offset);

        // _id
        if ($object->_id) {
            if ($this->outWithPerm($object)) {
                return [
                    "list"     => [$object],
                    "total"    => 1,
                    "step"     => $limit,
                    "offset"   => $offset,
                    "paginate" => false,
                ];
            }

            throw new CFHIRExceptionForbidden();
        }

        // specific search
        if ($specificSearch = $this->specificSearch($data, $limit, $offset)) {
            [$list, $total] = $specificSearch;
        } else {
            $ljoin = [];
            $order = $object->getPrimaryKey() . " DESC";
            $where = $this->getWhere($object, $ljoin);
            $list  = $object->loadList($where, $order, $limit, null, $ljoin);
            $total = $object->countList($where, null, $ljoin);
        }

        $list = array_filter(
            $list,
            function ($obj) {
                return $this->outWithPerm($obj);
            }
        );

        $step = null;
        if ($count_parameter = $this->getParameter('_count')) {
            $step = $count_parameter->getValue();
        }

        // limit
        $step = $step ? min($step, CFHIRResponse::SEARCH_MAX_ITEMS) : CFHIRResponse::SEARCH_MAX_ITEMS;

        return [
            "list"     => $list,
            "total"    => $total,
            "step"     => $step,
            "offset"   => $offset,
            "paginate" => true,
        ];
    }

    /**
     * @param array $data
     *
     * @return int
     */
    protected function getOffset(): int
    {
        if (!$offset_parameter = $this->getParameter('_offset')) {
            return 0;
        }

        return (int)$offset_parameter->getValue();
    }

    /**
     * @param ParameterBag $params
     *
     */
    public function setParameter(ParameterBag $params): void
    {
        if (!$this->parameters) {
            $this->parameters = ['fhir' => new ParameterBag(), 'all' => new ParameterBag()];
        }

        foreach ($params->all() as $key => $value) {
            if ($value instanceof SearchParameter) {
                $values   = $this->getParameters($value->getParameterName()) ?: [];
                $values[] = $value;
                $this->parameters['fhir']->set($value->getParameterName(), $values);
            } else {
                $this->parameters['all']->set($key, $value);
            }
        }
    }

    /**
     * @param string $parameter_name
     *
     * @return SearchParameter|null
     */
    public function getParameter(string $key): ?SearchParameter
    {
        if (!$this->parameters) {
            return null;
        }

        /** @var ParameterBag $parameters */
        if (!$parameters = $this->parameters['fhir'] ?? null) {
            return null;
        }

        /** @var SearchParameter[] $search_parameters */
        $search_parameters = $parameters->get($key);

        return $search_parameters ? end($search_parameters) : null;
    }

    /**
     * @param string $parameter_name
     *
     * @return SearchParameter[]|null
     */
    public function getParameters(?string $key = null): ?array
    {
        if (!$this->parameters) {
            return null;
        }

        if (!$parameters = $this->parameters['fhir'] ?? null) {
            return null;
        }

        return $key ? $parameters->get($key) : $parameters->all();
    }

    /**
     * @param string $key
     *
     * @return mixed
     */
    public function getParameterBrut(string $key)
    {
        if (!$this->parameters) {
            return null;
        }

        /** @var ParameterBag $parameters */
        $parameters = $this->parameters['all'] ?? null;

        if ($parameters) {
            return $parameters->get($key);
        }

        return null;
    }

    /**
     * @param string $key
     *
     * @return mixed
     */
    public function getParametersBrut()
    {
        if (!$this->parameters) {
            return null;
        }

        $parameters = $this->parameters['all'] ?? null;

        return $parameters ? $parameters->all() : null;
    }

    /**
     * @param array       $data
     * @param string|null $offset
     *
     * @return string
     */
    protected function getLimit(?string $offset = null): string
    {
        $limit = null;
        if ($count_parameter = $this->getParameter('_count')) {
            $limit = $count_parameter->getValue();
        }

        // limit
        $limit = $limit ? min($limit, CFHIRResponse::SEARCH_MAX_ITEMS) : CFHIRResponse::SEARCH_MAX_ITEMS;

        // add offset
        if ($offset) {
            $limit = "$offset, $limit";
        }

        return $limit;
    }

    /**
     * @param array       $data
     * @param string      $limit
     *
     * @param string|null $offset
     *
     * @return array [list, total]
     */
    protected function specificSearch(?array $data, string $limit, ?string $offset = null): array
    {
        return [];
    }

    /**
     * @param CStoredObject $object
     *
     * @return array
     * @throws ApiRequestException
     */
    protected function getWhere(CStoredObject $object, array &$ljoin): array
    {
        $where = [];

        $parameters = array_filter(
            $this->getParameters(),
            function ($parameter_name) {
                return !str_starts_with($parameter_name, '_');
            },
            ARRAY_FILTER_USE_KEY
        );

        /** @var SearchParameter $parameter */
        foreach ($parameters as $parameter) {
            $parameter = reset($parameter);
            if (!array_key_exists($parameter->getParameterName(), $object->getProps())) {
                continue;
            }

            $where[] = $parameter->getSql($parameter->getParameterName(), $object->getDS());
        }

        return $where;
    }

    /**
     * @param string $profile
     *
     * @return CFHIRResource|null
     * @throws Exception
     * @deprecated
     */
    public function findProfile(string $profile): ?CFHIRResource
    {
        if ($profile === ($this::PROFILE_CLASS)::BASE_PROFILE . $this::RESOURCE_TYPE) {
            return $this;
        }

        $resources_by_profile = $this->findProfiles();

        $resource_matches = array_filter(
            $resources_by_profile,
            function (CFHIRResource $resource) use ($profile) {
                return $resource->getProfile() === $profile;
            }
        );

        if (count($resource_matches) === 1) {
            return reset($resource_matches);
        }

        return null;
    }

    /**
     * @return CFHIRResource[]
     * @throws Exception
     */
    public function findProfiles(): array
    {
        // todo ref this function
        // todo [profile] a changer
        // find context fhir version
        if ($this->isFHIRResource()) {
            $fhir_version = $this->getFHIRVersion();
        } else {
            $fhir_version = ($this->getFhirParentResource())->getFHIRVersion();
        }

        //$resources = (new CFHIRMap())->getResources($this->getResourceType());

        return array_filter(
            [],
            function (CFHIRResource $resource) {
                return $resource instanceof $this;
            }
        );
    }

    /**
     * @param string $interaction
     *
     * @return bool
     */
    public function hasInteraction(string $interaction): Boolean
    {
        return in_array($interaction, $this->getInteractions());
    }

    /**
     * @return string
     */
    public function getInteractions(): array
    {
        return $this->getCapabilities()->getInteractions();
    }

    /**
     * @param CFHIRResource[] $resources
     *
     * @return string[]
     */
    public function getProfiles(array $resources): array
    {
        return array_map(
            function ($resource) {
                return $resource->getProfile();
            },
            $resources
        );
    }

    /**
     * @param string $resource_id
     * @param string $version_id
     *
     * @return CStoredObject
     * @throws CFHIRExceptionNotFound
     * @throws Exception
     */
    public function loadObjectFromContext(string $resource_id, ?string $version_id = null): CStoredObject
    {
        $object = $this->getObject();

        // load by idex
        if ($this->_receiver) {
            $idex = CIdSante400::getMatch($object->_class, $this->_receiver->_tag_fhir, $resource_id);
            if (!$idex || !$idex->_id) {
                $msg = "Could not find " . $this::RESOURCE_TYPE . " #$resource_id"
                    . ($version_id ? "|$version_id" : '');
                throw new CFHIRExceptionNotFound($msg);
            }

            $object = $idex->loadTargetObject();
        } else {
            $object_retrieve = CStoredObject::loadByUuid($resource_id);
            /*
            if ($object_retrieve->_class !== $object->_class) {
                throw new CFHIRExceptionNotFound('Not found : object not match with object attempt');
            }*/

            $object = $object_retrieve;
        }

        // check object found
        if (!$object || !$object->_id) {
            throw new CFHIRExceptionNotFound("Could not find " . $this::RESOURCE_TYPE . " #$resource_id");
        }

        // get specific version of object
        if ($version_id !== null && $this::ACCEPT_VERSION_ID) {
            // check user log exist and target is object
            $user_log     = (new CUserLog())->load($version_id);
            $is_not_match = ($user_log->object_class != $object->_class) || ($user_log->object_id != $object->_id);
            if (!$user_log->_id || $is_not_match) {
                throw new CFHIRExceptionNotFound(
                    "Version #{$version_id} is not valid for resource '" . $this::RESOURCE_TYPE . "' #{$resource_id}"
                );
            }

            $object = $object->loadListByHistory($version_id);
            if (!is_object($object)) {
                throw new CFHIRExceptionNotFound("Resource with version_id '$version_id' not found");
            }
        }

        return $this->object = $object;
    }

    /**
     * Format address fhir type
     *
     * @param string $address  address
     * @param string $city     city
     * @param string $zip_code zip code
     * @param string $country  country
     * @param string $use      use
     * @param string $type     type
     *
     * @return CFHIRDataTypeAddress
     */
    function formatAddress($address = null, $city = null, $zip_code = null, $country = null, $use = null, $type = null)
    {
        return CFHIRDataTypeAddress::build(
            [
                "use"        => new CFHIRDataTypeCode($use),
                "type"       => new CFHIRDataTypeCode($type),
                "text"       => new CFHIRDataTypeString("$address $zip_code $country"),
                "line"       => new CFHIRDataTypeString($address),
                "city"       => new CFHIRDataTypeString($city),
                "postalCode" => new CFHIRDataTypeString($zip_code),
                "country"    => new CFHIRDataTypeString($country),
            ]
        );
    }

    /**
     * @param string $value
     *
     * @return string
     */
    public function formatGender(?string $value): string
    {
        switch ($value) {
            case 'm':
                $gender = 'male';
                break;

            case 'f':
                $gender = 'female';
                break;

            default:
                $gender = 'unknown';
        }

        return $gender;
    }



    /**
     * @param string|CFHIRResource $resource_or_class
     * @param CStoredObject        $object
     *
     * @return CFHIRDataTypeReference
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function addReference($resource_or_class, ?CStoredObject $object = null): CFHIRDataTypeReference
    {
        if (is_string($resource_or_class)) {
            if (!is_subclass_of($resource_or_class, CFHIRResource::class)) {
                throw new CFHIRException('parameter given to resource_class should be an object of CFHIRResource');
            }

            if (!$object) {
                throw new CFHIRException('Add reference between resources must have an Object when resource class is given');
            }

            $resource_or_class = new $resource_or_class();
        } else {
            if (!$resource_or_class instanceof CFHIRResource) {
                throw new CFHIRException('parameter given to resource_class should be an object of CFHIRResource');
            }
        }

        return CFHIRDataTypeReference::build(
            [
                'reference' => $this->getResourceIdentifier($resource_or_class, $object),
            ]
        );
    }

    /**
     * @param CFHIRResource $resource
     * @param CStoredObject        $object
     *
     * @return string
     */
    protected function getResourceIdentifier(CFHIRResource $resource, ?CStoredObject $object): string
    {
        if (!$object) {
            $identifier = ($resource->id && !$resource->id->isNull())
                ? $resource->id->getValue()
                : CMbSecurity::generateUUID();
        } else {
            $identifier = $this->getInternalId($object);
        }

        return $resource::RESOURCE_TYPE . "/" . $identifier;
    }

    protected function getOxIdentifier(CStoredObject $object): string
    {
        return $object->getUuid();
    }

    /**
     * @param CStoredObject $object
     *
     * @return string
     */
    protected function getInternalId(CStoredObject $object): string
    {
        // If we send a resource (POST | PUT)
        // todo gestion dans le client de la sauvegarde de l'idex lors de la création + update
        if ($this->_receiver) {
            $idex = CIdSante400::getMatch($object->_class, $this->_receiver->_tag_fhir, null, $object->_id);
            if ($idex && $idex->_id) {
                return $idex->id400;
            }
        }

        return $this->getOxIdentifier($object);
    }

    /**
     * @param CFHIRResource $resource
     *
     * @return CFHIRDataTypeReference
     */
    public static function buildReference(CFHIRResource $resource): CFHIRDataTypeReference
    {
        return $resource->addReference(get_class($resource), $resource->object);
    }

    /**
     * @return bool
     */
    public function isSummary(): bool
    {
        return false;
    }

    /**
     * @return bool
     */
    public function isContained(): bool
    {
        return $this->is_contained;
    }

    /**
     * @param CMediusers $mediusers
     *
     * @return CFHIRDataTypeCoding[]
     * @throws Exception
     */
    public function setPractitionerSpecialty(CMediusers $mediusers): array
    {
        $current_user = CMediusers::get();
        $function_id  = null;
        $group_id     = null;

        if (CAppUI::isCabinet()) {
            $function_id = $current_user->function_id;
        } elseif (CAppUI::isGroup()) {
            $group_id = CGroups::loadCurrent()->_id;
        }

        $medecin = new CMedecin();
        $medecin = $medecin->loadFromRPPS($mediusers->rpps, $function_id, $group_id);

        if (!$medecin->_id || !$medecin->disciplines) {
            return [];
        }

        $exploded_code = explode(' : ', $medecin->disciplines);

        $system      = 'https://mos.esante.gouv.fr/NOS/TRE_R38-SpecialiteOrdinale/FHIR/TRE-R38-SpecialiteOrdinale';
        $code        = $exploded_code[0];
        $displayName = $exploded_code[1];

        return CFHIRDataTypeCoding::addCoding($system, $code, $displayName, []);
    }
}
