<?php

/**
 * @package Hospilibre\fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Resources\R4\Binary;

use Exception;
use Ox\Core\CMbException;
use Ox\Core\CStoredObject;
use Ox\Interop\Cda\CCdaTools;
use Ox\Interop\Fhir\Contracts\Resources\ResourceBinaryInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeBase64Binary;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeId;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;
use Ox\Interop\Fhir\Event\CFHIREvent;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionRead;
use Ox\Interop\Fhir\Profiles\CFHIR;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Ox\Interop\Fhir\Utilities\CCapabilitiesResource;
use Hospilibre\Files\CDocumentItem;
use Hospilibre\Files\CDocumentReference;
use Hospilibre\Files\CFile;
use Hospilibre\PlanningOp\CSejour;

/**
 * Description
 */
class CFHIRResourceBinary extends CFHIRResource implements ResourceBinaryInterface
{
    // constants
    /** @var string  */
    public const RESOURCE_TYPE = 'Binary';

    /** @var string */
    public const VERSION_NORMATIVE = '4.0';

    /** @var CFHIRDataTypeId[] */
    public $id;

    /** @var CFHIRDataTypeCode */
    public $contentType;

    /** @var CFHIRDataTypeString */
    public $content;

    /** @var CFHIRDataTypeReference */
    public $securityContext;

    /** @var CFHIRDataTypeBase64Binary */
    public $data;

    /**
     * @inheritdoc
     */
    public function getClass(): ?string
    {
        return CDocumentReference::class;
    }

    /**
     * @return CCapabilitiesResource
     */
    public function generateCapabilities(): CCapabilitiesResource
    {
        return (parent::generateCapabilities())
            ->addInteractions(
                [
                    CFHIRInteractionRead::NAME
                ]
            );
    }

    /**
     * @inheritdoc
     */
    public function build(CStoredObject $object, CFHIREvent $event): void
    {
        /** @var CDocumentItem $object */
        parent::build($object, $event);

        $this->id = CFHIR::generateUUID();

        $this->contentType = $this->getContentType($object);
        $this->content     = $this->putContent($object);
    }

    /**
     * @param array       $data Data to handle
     *
     * @param string      $limit
     * @param string|null $offset
     *
     * @return CStoredObject[]
     * @throws Exception
     */
    public function specificSearch(?array $data, string $limit, ?string $offset = null): array
    {
        /** @var CSejour $object */
        $object = $this->getObject();

        /** @var CDocumentReference[] $list */
        $list = $object->loadList(null, null, $limit);
        foreach ($list as $_list) {
            $_list->loadRefObject();
        }
        $total = $object->countList();

        return [$list, $total];
    }

    /**
     * @inheritdoc
     */
    protected function map(CStoredObject $object): void
    {
        /** @var CDocumentReference $document_reference */
        $document_reference = $object;

        $this->id[] = new CFHIRDataTypeId($document_reference->_id);

        $document_item = $document_reference->loadRefObject();

        $this->contentType = $this->getContentType($document_item);
        $this->content     = $this->putContent($document_item);
    }

    /**
     * Put content on FHIR Resource
     *
     * @param CDocumentItem $object object
     *
     * @return CFHIRDataTypeString
     * @throws CMbException
     */
    function putContent(CDocumentItem $object)
    {
        //Génération du PDF
        if ($object instanceof CFile) {
            $path = $object->_file_path;
            switch ($object->file_type) {
                case "image/tiff":
                    $mediaType = "image/tiff";
                    break;
                case "application/pdf":
                    $mediaType = $object->file_type;
                    $path      = CCdaTools::generatePDFA($object->_file_path);
                    break;
                case "image/jpeg":
                case "image/jpg":
                    $mediaType = "image/jpeg";
                    break;
                case "application/rtf":
                    $mediaType = "text/rtf";
                    break;
                case "text/plain":
                    $mediaType = "text/plain";
                    break;
                default:
                    throw new CMbException("fhir-msg-Document type authorized in FHIR|pl");
            }
        } else {
            if ($msg = $object->makePDFpreview(1, 0)) {
                throw new CMbException($msg);
            }
            $file = $object->_ref_file;
            $path = CCdaTools::generatePDFA($file->_file_path);
        }

        // TODO : Voir si on fait un PDFA
        return new CFHIRDataTypeString(base64_encode($object->getBinaryContent()));
        //return new CFHIRDataTypeString(base64_encode(file_get_contents($path)));
    }

    /**
     * Get type
     *
     * @param CDocumentItem $object object
     *
     * @return string
     * @throws CMbException
     */
    function getContentType(CDocumentItem $object)
    {
        $mediaType = "application/pdf";

        if ($object instanceof CFile) {
            switch ($object->file_type) {
                case "image/tiff":
                    $mediaType = "image/tiff";
                    break;
                case "application/pdf":
                    $mediaType = $object->file_type;
                    break;
                case "image/jpeg":
                case "image/jpg":
                    $mediaType = "image/jpeg";
                    break;
                case "application/rtf":
                    $mediaType = "text/rtf";
                    break;
                case "text/plain":
                    $mediaType = "text/plain";
                    break;
                default:
                    throw new CMbException("fhir-msg-Document type authorized in FHIR|pl");
            }
        }

        return new CFHIRDataTypeString($mediaType);
    }
}
