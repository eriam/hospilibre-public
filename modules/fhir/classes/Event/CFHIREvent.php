<?php

/**
 * @package Hospilibre\fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Event;

use Ox\Core\Autoload\IShortNameAutoloadable;
use Ox\Core\CMbObject;
use Ox\Interop\Eai\CExchangeDataFormat;
use Ox\Interop\Eai\CInteropSender;
use Ox\Interop\Fhir\Actors\CReceiverFHIR;
use Ox\Interop\Fhir\CExchangeFHIR;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Bundle\CFHIRDataTypeBundleEntry;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Bundle\CFHIRDataTypeBundleRequest;
use Ox\Interop\Fhir\Exception\CFHIRException;
use Ox\Interop\Fhir\Exception\CFHIRExceptionNotFound;
use Ox\Interop\Fhir\Profiles\CFHIR;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Ox\Interop\Fhir\Resources\R4\Appointment\CFHIRResourceAppointment;
use Ox\Interop\Fhir\Resources\R4\ConceptMap\CFHIRResourceConceptMap;
use Ox\Interop\Fhir\Resources\R4\DocumentManifest\CFHIRResourceDocumentManifest;
use Ox\Interop\Fhir\Resources\R4\DocumentReference\CFHIRResourceDocumentReference;
use Ox\Interop\Fhir\Resources\R4\Binary\CFHIRResourceBinary;
use Ox\Interop\Fhir\Resources\R4\Bundle\CFHIRResourceBundle;
use Ox\Interop\Fhir\Resources\R4\Patient\CFHIRResourcePatient;
use Ox\Interop\Fhir\Resources\R4\Practitioner\CFHIRResourcePractitioner;
use Ox\Interop\Fhir\Resources\R4\Schedule\CFHIRResourceSchedule;
use Hospilibre\Files\CDocumentItem;

/**
 * Description
 */
abstract class CFHIREvent implements IShortNameAutoloadable
{
    /** @var string */
    public $event_type;

    /** @var string */
    public $profil;

    /** @var string */
    public $transaction;

    /** @var string */
    public $type;

    /** @var string */
    public $code;

    /** @var CReceiverFHIR */
    public $_receiver;

    /** @var CInteropSender */
    public $_sender;

    /** @var CExchangeDataFormat */
    public $_data_format;

    /** @var CExchangeFHIR */
    public $_exchange_fhir;

    /** @var string */
    public $tag_profile = "http://ihe.net/fhir/tag/";

    /**
     * Construct
     *
     * @return void
     */
    public function __construct()
    {
        $this->event_type = "FHIR";
    }

    /**
     * Build event
     *
     * @param CMbObject $object Object
     *
     * @return CFHIRResource
     * @see parent::build()
     *
     */
    public function build(CMbObject $object): CFHIRResource
    {
        return new CFHIRResource();
    }

    /**
     * Get event class
     *
     * @param CFHIRResource $resource Resource
     *
     * @return string
     * @throws CFHIRExceptionNotFound
     */
    public static function getEventClass(CFHIRResource $resource): ?string
    {
        $classname = null;
        switch ($resource->getResourceType()) {
            case CFHIRResourceDocumentReference::RESOURCE_TYPE:
                $classname = CFHIREventProvideDocumentBundle::class;
                break;
            case CFHIRResourceConceptMap::RESOURCE_TYPE:
                $classname = CFHIREventConceptMap::class;
                break;
            case CFHIRResourceAppointment::RESOURCE_TYPE:
                $classname = CFHIREventAppointment::class;
                break;
            case CFHIRResourcePractitioner::RESOURCE_TYPE:
                $classname = CFHIREventPractitioner::class;
                break;
            case CFHIRResourcePatient::RESOURCE_TYPE:
                $classname = CFHIREventPatient::class;
                break;
            case CFHIRResourceSchedule::RESOURCE_TYPE:
                $classname = CFHIREventSchedule::class;
                break;
            default:
                throw new CFHIRExceptionNotFound("Could not find event class name");
        }

        return $classname;
    }

    /**
     * Build Document Manifest
     *
     * @param CDocumentItem       $object object
     * @param CFHIRResourceBundle $bundle bundle
     *
     * @return CFHIRResourceDocumentManifest
     */
    public function addDocumentManifest(
        CDocumentItem $object,
        CFHIRResourceBundle $bundle
    ): CFHIRResourceDocumentManifest {
        // Construction du DocumentManifest => Equivalent du lot de soumission en XDS
        $manifest = new CFHIRResourceDocumentManifest();
        $manifest->build($object, $this);

        // Bundle . entry . request
        $datatype_request = CFHIRDataTypeBundleRequest::build(
            [
                'method' => 'POST',
                'url' => CFHIRResource::buildReference($manifest)
            ]
        );

        // Bundle . entry
        $bundle->entry[] = CFHIRDataTypeBundleEntry::build(
            [
                'fullUrl' => "urn:uuid:" . CFHIR::generateUUID(),
                'resource' => $manifest,
                'request' => $datatype_request
            ]
        );

        return $manifest;
    }

    /**
     * Build Document Reference
     *
     * @param CDocumentItem                 $object           object
     * @param CFHIRResourceBundle           $bundle           bundle
     * @param CFHIRResourceDocumentManifest $documentManifest document manifest
     *
     * @return CFHIRResourceDocumentReference
     * @throws CFHIRException
     */
    public function addDocumentReference(
        CDocumentItem $object,
        CFHIRResourceBundle $bundle,
        CFHIRResourceDocumentManifest $documentManifest
    ): CFHIRResourceDocumentReference {
        $documentReference = new CFHIRResourceDocumentReference();
        $documentReference->build($object, $this);

        // Bundle . entry . request
        $datatype_request = CFHIRDataTypeBundleRequest::build(
            [
                'method' => 'POST',
                'url' => CFHIRResource::buildReference($documentReference)
            ]
        );

        // Bundle . entry
        $bundle->entry[] = CFHIRDataTypeBundleEntry::build(
            [
                'fullUrl' => $documentManifest->_full_url_document_reference,
                'resource' => $documentReference,
                'request' => $datatype_request
            ]
        );

        return $documentReference;
    }

    /**
     * Build Binary Reference
     *
     * @param CDocumentItem       $object object
     * @param CFHIRResourceBundle $bundle bundle
     *
     * @return CFHIRResourceBinary
     * @throws CFHIRException
     */
    public function addBinaryReference(CDocumentItem $object, CFHIRResourceBundle $bundle): CFHIRResourceBinary
    {
        $binaryResource = new CFHIRResourceBinary();
        $binaryResource->build($object, $this);

        // Bundle . entry . request
        $datatype_request = CFHIRDataTypeBundleRequest::build(
            [
                'method' => 'POST',
                'url'    => CFHIRResource::buildReference($binaryResource),
            ]
        );

        // Bundle . entry
        $bundle->entry[] = CFHIRDataTypeBundleEntry::build(
            [
                'fullUrl'  => $object->_guid,
                'resource' => $binaryResource,
                'request'  => $datatype_request,
            ]
        );

        return $binaryResource;
    }
}
