<?php

/**
 * @package Hospilibre\Fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Resources\R4\DocumentReference;

use DOMDocument;
use DOMNode;
use Exception;
use Ox\Core\CHTTPClient;
use Ox\Core\CMbDT;
use Ox\Core\CMbException;
use Ox\Core\CMbObject;
use Ox\Core\CStoredObject;
use Ox\Core\CView;
use Ox\Interop\Cda\CCdaTools;
use Ox\Interop\Eai\CDomain;
use Ox\Interop\Eai\CInteropReceiver;
use Ox\Interop\Eai\CMbOID;
use Ox\Interop\Fhir\CFHIRXPath;
use Ox\Interop\Fhir\Contracts\Resources\ResourceDocumentReferenceInterface;
use Ox\Interop\Fhir\Controllers\CFHIRController;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeBase64Binary;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeBoolean;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeId;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeInstant;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeInteger;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\DocumentReference\CFHIRDataTypeDocumentReferenceContent;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\DocumentReference\CFHIRDataTypeDocumentReferenceContext;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\DocumentReference\CFHIRDataTypeDocumentReferenceRelatesTo;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeAttachment;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCoding;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeIdentifier;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;
use Ox\Interop\Fhir\Event\CFHIREvent;
use Ox\Interop\Fhir\Exception\CFHIRException;
use Ox\Interop\Fhir\Exception\CFHIRExceptionBadRequest;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionCreate;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionDelete;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionRead;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionSearch;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionUpdate;
use Ox\Interop\Fhir\Profiles\CFHIR;
use Ox\Interop\Fhir\Resources\CFHIRDomainResource;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Ox\Interop\Fhir\Resources\R4\DocumentManifest\CFHIRResourceDocumentManifest;
use Ox\Interop\Fhir\Resources\R4\Encounter\CFHIRResourceEncounter;
use Ox\Interop\Fhir\Resources\R4\Patient\CFHIRResourcePatient;
use Ox\Interop\Fhir\Resources\R4\Practitioner\CFHIRResourcePractitioner;
use Ox\Interop\Fhir\Utilities\CCapabilitiesResource;
use Ox\Interop\Fhir\Utilities\SearchParameters\SearchParameterReference;
use Ox\Interop\InteropResources\valueset\CXDSValueSet;
use Hospilibre\Cabinet\CConsultation;
use Hospilibre\CompteRendu\CCompteRendu;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Files\CDocumentItem;
use Hospilibre\Files\CDocumentManifest;
use Hospilibre\Files\CDocumentReference;
use Hospilibre\Files\CFile;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Patients\CPatient;
use Hospilibre\PlanningOp\COperation;
use Hospilibre\PlanningOp\CSejour;
use Hospilibre\Sante400\CHyperTextLink;
use Hospilibre\Sante400\CIdSante400;

/**
 * FIHR document reference resource
 */
class CFHIRResourceDocumentReference extends CFHIRDomainResource implements ResourceDocumentReferenceInterface
{
    // constants
    /** @var string */
    public const RESOURCE_TYPE = 'DocumentReference';

    /** @var string */
    public const SYSTEM = 'urn:ietf:rfc:3986';

    // attributes
    /** @var CFHIRDataTypeIdentifier */
    public $masterIdentifier;

    /** @var CFHIRDataTypeCode */
    public $status;

    /** @var CFHIRDataTypeCode */
    public $docStatus;

    /** @var CFHIRDataTypeCodeableConcept */
    public $type;

    /** @var CFHIRDataTypeCodeableConcept[] */
    public $category;

    /**
     * @var CFHIRDataTypeCoding
     * @deprecated
     */
    public $class;

    /** @var CFHIRDataTypeReference */
    public $subject;

    /** @var CFHIRDataTypeInstant */
    public $date;

    /** @var CFHIRDataTypeReference[] */
    public $author;

    /** @var CFHIRDataTypeReference */
    public $authenticator;

    /** @var CFHIRDataTypeReference */
    public $custodian;

    /** @var CFHIRDataTypeDocumentReferenceRelatesTo[] */
    public $relatesTo;

    /** @var CFHIRDataTypeString */
    public $description;

    /** @var CFHIRDataTypeCodeableConcept[] */
    public $securityLabel;

    /** @var CFHIRDataTypeDocumentReferenceContent[] */
    public $content;

    /** @var CFHIRDataTypeDocumentReferenceContext */
    public $context;

    /**
     * @var CFHIRDataTypeInstant[]
     * @deprecated
     */
    public $indexed;

    /** @var DocumentReferenceMappingInterface */
    protected $object_mapping;

    /**
     * @return CCapabilitiesResource
     */
    public function generateCapabilities(): CCapabilitiesResource
    {
        return (parent::generateCapabilities())
            ->addInteractions(
                [
                    CFHIRInteractionRead::NAME,
                    CFHIRInteractionSearch::NAME,
                    CFHIRInteractionCreate::NAME,
                    CFHIRInteractionDelete::NAME,
                    CFHIRInteractionUpdate::NAME,
                ]
            )
            ->addSearchAttributes(
                [
                    new SearchParameterReference('encounter'),
                    new SearchParameterReference('patient'),
                ]
            );
    }

    /**
     * Return the mime type
     *
     * @param String $type type
     *
     * @return null|string
     */
    public static function getFileType($type)
    {
        $file_type = null;

        $type = strtolower($type);

        switch ($type) {
            case "image/jpeg":
            case "image/jpg":
                $file_type = ".jpeg";
                break;
            case "image/png":
                $file_type = ".png";
                break;
            case "application/rtf":
            case "text/rtf":
                $file_type = ".rtf";
                break;
            case "image/tiff":
                $file_type = ".tiff";
                break;
            case "application/pdf":
                $file_type = ".pdf";
                break;
            case "text/plain":
                $file_type = ".txt";
                break;
            default:
                break;
        }

        return $file_type;
    }

    /**
     * Get document reference in response
     *
     * @param DOMDocument $dom dom
     *
     * @return array
     * @throws Exception
     */
    public static function getDocumentReferenceFromXML(DOMDocument $dom)
    {
        $xpath = new CFHIRXPath($dom);

        $files = [];
        switch ($dom->documentElement->nodeName) {
            case "DocumentReference":
                $file                              = $dom->documentElement;
                $mbFile                            = new CFile();
                $mbFile                            = self::mapDocumentReferenceFromXML($mbFile, $file);
                $files[$mbFile->_fhir_resource_id] = $mbFile;
                break;
            case "Bundle":
                $entries = $xpath->query("//fhir:entry");
                foreach ($entries as $_entry) {
                    $file = $xpath->queryUniqueNode("fhir:resource/fhir:DocumentReference", $_entry);

                    $mbFile                            = new CFile();
                    $mbFile                            = self::mapDocumentReferenceFromXML($mbFile, $file);
                    $files[$mbFile->_fhir_resource_id] = $mbFile;
                }
                break;
            default:
        }

        return $files;
    }

    /**
     * @inheritdoc
     *
     * @param CFile $object
     *
     * @throws \Exception
     */
    public static function mapDocumentReferenceFromXML(CMbObject $object, DOMNode $node)
    {
        $xpath = new CFHIRXPath($node->ownerDocument);

        $object->_fhir_resource_id = $xpath->getAttributeValue("fhir:id", $node);
        $object->annule            = $xpath->getAttributeValue("fhir:status", $node) == "current" ? 0 : 1;

        // Si on a pas la balise <created>, on prend la balise <indexed>
        $created           = $xpath->getAttributeValue("fhir:created", $node);
        $object->file_date = $created ? $created : CMbDT::dateTime($xpath->getAttributeValue("fhir:indexed", $node));

        // TODO : On commente pour le connectathon. A prendre en compte plusieurs authors dans une réponse
        /*$practitionner_value = $xpath->getAttributeValue("fhir:author/fhir:reference", $node);
        $practitionner_id    = preg_replace("#Practitioner/#", "", $practitionner_value);

        if ($practitionner_id) {
          $practitionner = new CMediusers();
          $practitionner->load($practitionner_id);

          if ($practitionner->_id) {
            $object->author_id   = $practitionner_id;
            $object->_ref_author = $practitionner;
          }
        }*/

        // Récupération contenu (le contenu est soit dans le message, soit il faut aller le requÎºter)
        $object->file_name = $xpath->getAttributeValue("fhir:content/fhir:attachment/fhir:title", $node);
        $object->file_type = $xpath->getAttributeValue("fhir:content/fhir:attachment/fhir:contentType", $node);
        $url               = $xpath->getAttributeValue("fhir:content/fhir:attachment/fhir:url", $node);

        if ($url) {
            // TODO XDS TOOLKIT : xds toolkit retourne directement le contenu et non une resource binary
            // Si la chaine n'est pas en base64, on l'encode en base64 car pour l'affichage il faut que Î·a soit encodé
            //$object->_binary_content = !CFHIRResource::validBase64($binary_content) ? base64_encode($binary_content) : $binary_content;

            $content = null;
            // Soit le contenu du fichier est dans la trame (<Binary>), soit il faut une requÎºte GET pour récupérer le contenu
            $entry_node = $xpath->getNode("//fhir:fullUrl[@value='$url']/ancestor::*[position()=1]");

            // Récupération du contenu dans la trame
            if ($entry_node && $entry_node->nodeName == "entry") {
                $content_value = $xpath->getAttributeValue("fhir:resource/fhir:Binary/fhir:content", $entry_node);

                if ($content_value) {
                    $content = base64_decode($content_value);
                }
            } // Récupération du binary en lanÎ·ant une autre requÎºte
            else {
                $binary_resource             = CFHIRResource::getExterneResource($url, "binary");
                $document_reference_resource = new self();
                $content                     = $document_reference_resource->getContentFromBinaryResource(
                    $binary_resource,
                    $url
                );

                // Si la chaine n'est pas en base64, on l'encode en base64 car pour l'affichage il faut que Î·a soit encodé
                $content = !CFHIRResource::validBase64($content) ? base64_encode($content) : $content;
            }

            $object->_binary_content = $content;
        } else {
            $object->_binary_content = $xpath->getAttributeValue("fhir:content/fhir:attachment/fhir:data", $node);
        }

        CView::setSession($object->_fhir_resource_id, $object->_binary_content);
        CView::setSession($object->_fhir_resource_id . "_file_type", $object->file_type);

        // Récupération contexte (type sejour)
        $encounter_value = $xpath->getAttributeValue("fhir:context/fhir:encounter/fhir:reference", $node);
        $encounter_id    = preg_replace("#Encounter/#", "", $encounter_value);

        if ($encounter_id) {
            $sejour = new CSejour();
            $sejour->load($encounter_id);

            if ($sejour->_id) {
                $object->object_id    = $sejour->_id;
                $object->object_class = $sejour->_class;
                $sejour->loadRefPatient();
                $object->_ref_object = $sejour;
            }
        }

        return $object;
    }

    /**
     * @inheritdoc
     */
    public function getClass(): ?string
    {
        return CDocumentReference::class;
    }


    /**
     * @param array       $data
     * @param string      $limit
     * @param string|null $offset
     *
     * @return array
     * @throws CFHIRExceptionBadRequest
     * @throws Exception
     */

    //public function specificSearch(?array $data, string $limit, ?string $offset = null): array
    //{
    //    $ljoin = [
    //        "document_manifest" => "`document_reference`.`document_manifest_id` = `document_manifest`.`document_manifest_id`"
    //    ];
    //    /** @var CDocumentReference $object */
    //    $object = $this->getObject();
    //    $ljoin = [];
    //   $where = $this->getCustomWhere($data, $object, $ljoin);
    //
    //    $list  = $object->loadList($where, null, $limit, null, $ljoin);
    //    $total = $object->countList($where, null, $ljoin);
    //
    //    return [$list, $total];
    //}

    /**
     * Makes a WHERE clause from data
     *
     * @param array              $data          Input data
     * @param CDocumentReference $doc_reference Document manifest
     * @param array              $ljoin         LEFT JOIN clause
     *
     * @return array
     * @throws Exception
     */
    private function getCustomWhere($data, CMbObject $doc_reference, &$ljoin)
    {
        $ds = $doc_reference->getDS();

        // patient
        if (isset($data["patient"]) && count($data["patient"])) {
            $where["document_manifest.patient_reference"] = " = '" . $data["patient"][0][1] . "'";
        }

        // patient.identifier
        if (isset($data["patient.identifier"]) && count($data["patient.identifier"])) {
            $identifier = $data["patient.identifier"];
            [$system, $value] = explode("|", $identifier[0][1]);

            $system = str_replace("urn:oid:", "", $system);

            $domain      = new CDomain();
            $domain->OID = $system;
            $domain->loadMatchingObject();

            if (!$domain->_id) {
                throw new CFHIRExceptionBadRequest("sourceIdentifier Assigning Authority not found");
            }

            $idex                                  = CIdSante400::getMatch("CPatient", $domain->tag, $value);
            $where["document_manifest.patient_id"] = " = '{$idex->object_id}'";
        }

        // status
        if (isset($data["status"]) && count($data["status"])) {
            $query_item                         = $data["status"][0];
            $where["document_reference.status"] = $ds->prepare("$query_item[0] ?", $query_item[1]);
        }

        $where["document_reference.initiator"] = " = 'server' ";
    }

    /**
     * @param array  $data   data
     * @param string $format format
     *
     * @return CDocumentReference
     * @throws CFHIRException
     */
    //    public function interactionCreate(?array $data, ?string $format): CStoredObject
    //    {
    //        $dom = CFHIRParser::parse($data, $format);
    //        if (!$dom) {
    //            throw new CFHIRException("Impossible to retrieve data");
    //        }
    //
    //        return $this->handleCreate($dom);
    //    }

    /**
     * Handle create
     *
     * @param DOMDocument $dom dom
     *
     * @return CDocumentReference[]
     * @throws CFHIRException
     */
    public function handleCreate(DOMDocument $dom): array
    {
        $xpath = new CFHIRXPath($dom);

        /*
          Dans le cas d'une création :
          1/ Note : le serveur ne doit pas contenir compte des id d'un DocumentReference et du DocumentManifest
          2/ identifier le patient et vérifier qu'il existe bien, oÏ‰ que l'on peut le récupérer pour le créer
          3/ il doit vérifier qu'il peut récupérer le Binary
          4/ rechercher la cible Encounter de la ressource DocumentReference
          5/ créer le CFile
          6/ créer le DocumentManifest
          7/ créer le DocumentReference sur la cible ou le patient le cas échéant
          8/ Retourner un CFHIRResourceBundle.type = transaction-response
        */

        // Récupération du DocumentManifest - uniquement dans le cas de IHE
        $nodes_document_manifest = $xpath->query(
            "fhir:entry/fhir:resource/fhir:DocumentManifest",
            $dom->documentElement
        );
        if ($nodes_document_manifest->length == 0 || $nodes_document_manifest->length > 1) {
            throw new CFHIRException(
                "Node DocumentManifest not found or node DocumentManifest are too many"
                . "$nodes_document_manifest->length)"
            );
        }

        $node_document_manifest = $nodes_document_manifest->item(0);
        $doc_manifest           = CFHIRResourceDocumentManifest::mapping(
            $xpath,
            $node_document_manifest,
            $this,
            "FHIR"
        );

        // Récupération des DocumentReference
        $nodes_doc_reference = $xpath->query("fhir:entry/fhir:resource/fhir:DocumentReference", $dom->documentElement);
        $docs_reference      = [];
        foreach ($nodes_doc_reference as $_node_doc_reference) {
            $doc_reference = new CDocumentReference();
            $doc_reference->setActor($this->_sender);

            // Récupération du patient par l'URL
            $patient = $this->getSubject($xpath, $_node_doc_reference);

            // Récupération de la cible Encounter (CSejour)
            // $sejour = $this->getEncounter($xpath, $_node_doc_reference);

            // Pour le moment on rattache le document au patient sur le serveur
            $file = $this->getAttachment($xpath, $_node_doc_reference, $patient);

            $doc_manifest->repositoryUniqueID = "urn:oid:" . CMbOID::getOIDFromClass($patient) . "." . hexdec(uniqid());
            $doc_manifest->status             = $xpath->getAttributeValue("fhir:status", $node_document_manifest);
            $doc_manifest->created_datetime   = $xpath->getAttributeValue("fhir:created", $node_document_manifest);
            $doc_manifest->patient_id         = $patient->_id;
            if (isset($patient->_ref_url)) {
                $doc_manifest->patient_reference = $patient->_ref_url;
            }

            if (!$doc_manifest->_id) {
                $doc_manifest->treated_datetime = "now";
                $doc_manifest->store();
            }

            // Est-ce un remplacement ?
            $relatesTo = $xpath->query("fhir:relatesTo", $_node_doc_reference);

            CFHIRResourceDocumentReference::mapping($xpath, $_node_doc_reference, $doc_manifest, $doc_reference, $file);

            $docs_reference[] = [
                "DocumentManifest"  => $doc_manifest,
                "DocumentReference" => $doc_reference,
                "Binary"            => $doc_reference,
            ];
        }

        return $docs_reference;
    }

    /**
     * Mapping DocumentReference resource to create CDocumentReference
     *
     * @param CFHIRXPath         $xpath              xpath
     * @param DOMNode            $node_doc_reference node doc reference
     * @param CDocumentManifest  $documentManifest   document manifest
     * @param CDocumentReference $documentReference  document reference
     * @param CFile              $file               file
     *
     * @return void
     * @throws CFHIRException
     *
     */
    public static function mapping(
        CFHIRXPath $xpath,
        DOMNode $node_doc_reference,
        CDocumentManifest $documentManifest,
        CDocumentReference $documentReference,
        CFile $file
    ) {
        // Hash
        $documentReference->hash = $xpath->getAttributeValue(
            "fhir:content/fhir:attachment/fhir:hash",
            $node_doc_reference
        );
        // Size
        $documentReference->size = $xpath->getAttributeValue(
            "fhir:content/fhir:attachment/fhir:size",
            $node_doc_reference
        );
        if (!$documentReference->size) {
            $documentReference->size = $file->doc_size;
        } else {
            if ($documentReference->size != $file->doc_size) {
                throw new CFHIRException("Size of DocumentReference is differente of size in registry");
            }
        }
        // Status
        $documentReference->status = $xpath->getAttributeValue("fhir:status", $node_doc_reference);
        // SecurityLabel
        $documentReference->security_label = $xpath->getAttributeValue(
            "fhir:securityLabel/fhir:coding/fhir:display",
            $node_doc_reference
        );

        $documentReference->setObject($file);
        // CFile : version = 1
        $version                                 = 1;
        $documentReference->uniqueID             = "urn:uuid:" . CMbOID::getOIDFromClass(
                $file
            ) . "." . $file->_id . "." . $version;
        $documentReference->document_manifest_id = $documentManifest->_id;
        $documentReference->initiator            = "server";
        if ($msg = $documentReference->store()) {
            throw new CFHIRException("Impossible to store metadata of DocumentReference : $msg");
        }
    }

    /**
     * @inheritdoc
     */
    protected function map(CStoredObject $object): void
    {
        if ($this->object_mapping) {
            if (!$object->_id) {
                return;
            }

            parent::map($object);

            $this->mapMasterIdentifier();

            $this->mapStatus();

            $this->mapDocStatus();

            $this->mapType();

            $this->mapCategory();

            $this->mapSubject();

            $this->mapDate();

            $this->mapAuthor();

            $this->mapAuthenticator();

            $this->mapCustodian();

            $this->mapRelatesTo();

            $this->mapDescription();

            $this->mapSecurityLabel();

            $this->mapContent();

            $this->mapContext();

            return;
        }

        /** @var CDocumentReference $doc_reference */
        $doc_reference = $object;

        $this->id[] = new CFHIRDataTypeId($doc_reference->_id);

        $file         = $doc_reference->loadRefObject();
        $this->status = new CFHIRDataTypeBoolean($file->annule ? "superseded" : "current");
        $this->type   = CFHIRDataTypeCodeableConcept::build(
            [
                "coding" => CFHIRDataTypeCoding::build(
                    [
                        "system"  => new CFHIRDataTypeString("http://loinc.org"),
                        "code"    => new CFHIRDataTypeString("34133-9"),
                        "display" => new CFHIRDataTypeString("Summary of Episode Note"),

                    ]
                ),
                "text"   => "Summary of Episode Note",
            ]
        );

        $this->masterIdentifier = $this->addMasterIdentifier($doc_reference->uniqueID, "urn:ietf:rfc:3986");

        // TODO : DSTU 4 => y'a plus "created" et "indexed" y'a juste "date"
        $this->date    = new CFHIRDataTypeInstant($file instanceof CFile ? $file->file_date : $file->creation_date);
        $this->indexed = new CFHIRDataTypeInstant($file instanceof CFile ? $file->file_date : $file->creation_date);

        $author       = $file->loadRefAuthor();
        $this->author = $this->addReference(CFHIRResourcePractitioner::class, $author);

        $this->description = new CFHIRDataTypeString($file instanceof CFile ? $file->file_name : $file->nom);

        $url_document_reference = CFHIRController::getUrl(
            "fhir_read",
            [
                'resource'    => "Binary",
                'resource_id' => $doc_reference->_id,
            ]
        );

        $this->content = CFHIRDataTypeDocumentReferenceContent::build(
            [
                "attachment" => CFHIRDataTypeAttachment::build(
                    [
                        'text'        => 'totot',
                        "contentType" => new CFHIRDataTypeCode($file->file_type),
                        //"data"        => new CFHIRDataTypeBase64Binary(base64_encode($file->getBinaryContent())),
                        "url"         => new CFHIRDataTypeBase64Binary($url_document_reference),
                        "title"       => new CFHIRDataTypeString(
                            $file instanceof CFile ? $file->file_name : $file->nom
                        ),
                    ]
                ),
            ]
        );

        $context_data = [];
        $object       = $file->loadTargetObject();
        if ($object instanceof CSejour) {
            $context_data["encounter"] = $this->addReference(CFHIRResourceEncounter::class, $object);
        }

        $document_manifest = $doc_reference->loadRefDocumentManifest();
        if ($document_manifest->_id) {
            $context_data["related"] = $this->addReference(CFHIRResourceDocumentManifest::class, $document_manifest);
        }
        //$this->context = CFHIRDataTypeDocumentReferenceContext::build($context_data);
    }

    /**
     * @inheritdoc
     */
    public function build(CStoredObject $object, CFHIREvent $event): void
    {
        parent::build($object, $event);

        if (!$object instanceof CCompteRendu && !$object instanceof CFile) {
            throw  new CFHIRException("Object is not document item");
        }

        // On prend le CDocumentReference le plus récent
        $document_reference = new CDocumentReference();
        $document_reference->setObject($object);
        $document_reference->setActor($event->_receiver);
        /** @var CDocumentItem $version */
        $version                      = $object->_version;
        $document_reference->uniqueID = "urn:uuid:" . CMbOID::getOIDFromClass(
                $object
            ) . "." . $object->_id . "." . $version;
        $document_reference->loadMatchingObject();

        if (!$document_reference->_id) {
            throw new CFHIRException("Impossible to get DocumentReference");
        }

        $this->id = CFHIR::generateUUID();

        $group = $this->getGroup($object);
        if ($group) {
            // $this->addReference(CFHIRResourceOrganization::class, $group);
        }

        $object->loadTargetObject();
        $patient = new CPatient();
        if ($object->_ref_object instanceof CPatient) {
            $patient = $object->_ref_object;
        } elseif ($object->_ref_object instanceof CSejour || $object->_ref_object instanceof CConsultation
            || $object->_ref_object instanceof COperation
        ) {
            $patient = $object->_ref_object->loadRefPatient();
        }

        // XDS : DocumentEntry.uniqueId
        $version                = $object->_version;
        $this->masterIdentifier = $this->addMasterIdentifier(
            "urn:oid:" . CMbOID::getOIDFromClass($object) . "." . $object->_id . "." . $version,
            self::SYSTEM
        );

        // XDS : DocumentEntry.entryUUID
        //$this->identifier = CFHIRDataTypeIdentifier::addIdentifier($this->identifier, "urn:uuid:".CFHIR::generateUUID());

        // XDS : DocumentEntry.status
        $this->status = new CFHIRDataTypeCode($object->annule ? "superseded" : "current");

        // XDS : No correspondance
        //$this->docStatus = $this->addDocStatus();

        // XDS : DocumentEntry.type
        if ($object->type_doc_dmp) {
            $type          = explode("^", $object->type_doc_dmp);
            $xds_value_set = new CXDSValueSet();

            $xds_value_set = [
                "codeSystem"  => "http://loinc.org",
                "code"        => "34133-9",
                "displayName" => "Summary of Episode Note",
            ];
            //$this->type = $this->addTypeCodeCoding($xds_value_set->getTypeCode(CMbArray::get($type, 1)));
            $this->type = $this->addTypeCodeCoding($xds_value_set);
        }

        // XDS : DocumentEntry.class
        $xds_value_set = new CXDSValueSet();
        $values        = $xds_value_set->getClassCode();

        /*$values_data = array(
          "codeSystem"  => CMbArray::get($values, 1),
          "code"        => CMbArray::get($values, 0),
          "displayName" => CMbArray::get($values, 2)
        );*/
        $values_data = [
            "codeSystem"  => "urn:oid:1.3.6.1.4.1.19376.1.2.6.1",
            "code"        => "REPORTS",
            "displayName" => "Reports",
        ];
        $this->class = $this->addClassCode($values_data);

        // XDS : DocumentEntry.patientId
        $this->subject = $this->addSubject($patient);

        // XDS : DocumentEntry.submissionTime
        // TODO : Il ne faut pas le mettre en création
        //$this->created = $this->addCreated($object instanceof CCompteRendu ? $object->creation_date : $object->file_date);

        // XDS : DocumentEntry.submissionTime
        $this->indexed = new CFHIRDataTypeInstant(
            $object instanceof CCompteRendu ? $object->creation_date : $object->file_date
        );
        /** @var CDocumentItem $author */
        $author = $object->loadRefAuthor();

        // XDS : DocumentEntry.author
        $this->author = $this->addReference(CFHIRResourcePractitioner::class, $author);

        // XDS : DocumentEntry.legalAuthenticator
        $this->authenticator = $this->addReference(CFHIRResourcePractitioner::class, $author);

        // XDS : DocumentEntry.description
        $this->description = new CFHIRDataTypeString(
            $object instanceof CCompteRendu ? $object->nom : $object->file_name
        );

        // XDS : DocumentEntry.confidentialityCode
        $xds_value_set       = new CXDSValueSet();
        $this->securityLabel = $this->addSecurityLabel($xds_value_set->getConfidentialityCode());
        $values_data         = [
            "codeSystem"  => "http://hl7.org/fhir/v3/Confidentiality",
            "code"        => "N",
            "displayName" => "Normal",
        ];
        $this->securityLabel = $this->addSecurityLabel($values_data);

        // XDS : DocumentEntry.mimeType DocumentEntry.languageCode DocumentEntry.URI DocumentEntry.size DocumentEntry.title
        $this->content = $this->addAttachment($object);

        // XDS : DocumentEntry.eventCodeList DocumentEntry.serviceStartTime DocumentEntry.serviceStopTime
        // DocumentEntry.healthcareFacilityTypeCode XDS : DocumentEntry.practiceSettingCode DocumentEntry.sourcePatientInfo
        // DocumentEntry.sourcePatientId DocumentEntry.referenceList
        $this->context = $this->addContext(
            $object,
            $object->_ref_object instanceof CSejour ? $object->_ref_object : null
        );

        // XDS : Association
        // Permet le remplacement d'un document
        if ($object instanceof CCompteRendu && $document_reference->parent_id) {
            $this->relatesTo = $this->addAssociation($object, $document_reference, $event->_receiver);
        }
    }

    /**
     * Build class code field on resource
     *
     * @param array $values values
     *
     * @return CFHIRDataTypeCoding
     */
    function addClassCode($values)
    {
        return CFHIRDataTypeCoding::build(
            $this->formatCodeableConcept($values)
        );
    }

    /**
     * Build securityLabel field on resource
     *
     * @param array $values values
     *
     * @return CFHIRDataTypeCodeableConcept
     */
    function addSecurityLabel($values)
    {
        return CFHIRDataTypeCodeableConcept::build(
            $this->formatCodeableConcept($values)
        );
    }

    /**
     * Add association like XDS in relatesTo
     *
     * @param CCompteRendu       $cr                 cr
     * @param CDocumentReference $document_reference document reference
     * @param CInteropReceiver   $receiver           receiver
     *
     * @return CFHIRDataTypeDocumentReferenceRelatesTo
     * @throws CFHIRException
     */
    function addAssociation(CCompteRendu $cr, CDocumentReference $document_reference, CInteropReceiver $receiver)
    {
        $hypertext               = new CHyperTextLink();
        $hypertext->object_class = $document_reference->_class;
        $hypertext->object_id    = $document_reference->parent_id;
        $hypertext->name         = "DocReference_" . $receiver->_guid;
        $hypertext->loadMatchingObject();

        if (!$hypertext->_id) {
            return null;
        }

        return CFHIRDataTypeDocumentReferenceRelatesTo::build(
            [
                "code"   => new CFHIRDataTypeString("replaces"),
                "target" => CFHIRDataTypeReference::build(
                    [
                        /*"reference" => new CFHIRDataTypeString(
                          FhirController::getUrl("fhir_read", array(
                            'resource'    => "DocumentReference",
                            'resource_id' => $document_reference->parent_id
                          ))
                        )*/
                        "reference" => new CFHIRDataTypeString($hypertext->link),
                    ]
                ),
            ]
        );
    }

    /**
     * Build context field on resource
     *
     * @param CDocumentItem $object object
     * @param CSejour       $sejour sejour
     *
     * @return CFHIRDataTypeDocumentReferenceContext
     * @throws Exception
     */
    function addContext(CDocumentItem $object, CSejour $sejour = null)
    {
        $data = [];

        if ($sejour) {
            $sejour->loadRefPatient();
            $data["encounter"] = $this->addReference(CFHIRResourceEncounter::class, $sejour);
        }

        // DocumentEntry.eventCodeList XDS
        // TODO : A gerer
        /*$data["event"] = CFHIRDataTypeCodeableConcept::build(
          array(
            "coding" => CFHIRDataTypeCodeableConcept::build(
              array("system" => "titi")
            )
          )
        );*/

        // Period
        $date = $object instanceof CCompteRendu ? $object->creation_date : $object->file_date;
        /*$data["period"] = CFHIRDataTypePeriod::build(
          $this->formatPeriod($date, $date)
        );*/

        // DocumentEntry.healthcareFacilityTypeCode XDS
        $group         = $sejour ? $sejour->loadRefEtablissement() : CGroups::loadCurrent();
        $xds_value_set = new CXDSValueSet();
        //$values_healthcare_facility = $xds_value_set::getHealthcareFacilityTypeCode($group);
        $values_healthcare_facility = [
            "codeSystem"  => "http://snomed.info/sct",
            "code"        => "35971002",
            "displayName" => "Ambulatory care site",
        ];

        if ($values_healthcare_facility) {
            $data["facilityType"] = CFHIRDataTypeCodeableConcept::build(
                $this->formatCodeableConcept($values_healthcare_facility)
            );
        }

        // DocumentEntry.practiceSettingCode XDS
        $values_practice_setting_code = $xds_value_set::getPracticeSettingCode();
        $values_practice_setting_code = [
            "codeSystem"  => "http://connectathon.ihe",
            "code"        => "Practice-E",
            "displayName" => "Ophthalmology",
        ];

        if ($values_practice_setting_code) {
            $data["practiceSetting"] = CFHIRDataTypeCodeableConcept::build(
                $this->formatCodeableConcept($values_practice_setting_code)
            );
        }

        if ($sejour) {
            $data["sourcePatientInfo"] = $this->addReference(CFHIRResourcePatient::class, $sejour->_ref_patient);
        }

        return CFHIRDataTypeDocumentReferenceContext::build($data);
    }

    /**
     * Get groups from Model document
     *
     * @param CDocumentItem $object object
     *
     * @return CGroups|null
     */
    public function getGroup(CDocumentItem $object)
    {
        $group_id = null;

        if ($object instanceof CCompteRendu) {
            $group_id = $object->group_id;
        } elseif ($object instanceof CFile) {
            $group_id = $object->loadRefAuthor()->loadRefFunction()->group_id;
        }

        if (!$group_id) {
            $target = $object->loadTargetObject();

            if ($target instanceof CSejour) {
                $group_id = $target->group_id;
            }
        }

        if (!$group_id) {
            return null;
        }

        $group = new CGroups();
        $group->load($group_id);

        if (!$group || !$group->_id) {
            return null;
        }

        return $group;
    }

    /**
     * Get content of document
     *
     * @param CFHIRXPath $xpath              xpath
     * @param DOMNode    $node_doc_reference node
     * @param CMbObject  $object             object
     *
     * @return CFile
     * @throws CFHIRException|Exception
     */
    function getAttachment(CFHIRXPath $xpath, DOMNode $node_doc_reference, $object)
    {
        $nodes_attachment = $xpath->query("fhir:content/fhir:attachment", $node_doc_reference);
        $node_attachment  = $nodes_attachment->item(0);

        $content_type = $xpath->getAttributeValue("fhir:contentType", $node_attachment);
        if (!$content_type) {
            throw new CFHIRException(
                "Impossible to retrieve DocumentReference.content.attachment.contentType. This value must be present."
            );
        }

        $file            = new CFile();
        $file->file_type = $content_type;

        $name_file       = $xpath->getAttributeValue("fhir:title", $node_attachment);
        $file->file_name = $name_file ? $name_file : "Document_FHIR";

        $language       = $xpath->getAttributeValue("fhir:language", $node_attachment);
        $file->language = $language ? $language : "fr-FR";

        $data = $xpath->getAttributeValue("fhir:data", $node_attachment);
        $url  = $xpath->getAttributeValue("fhir:url", $node_attachment);

        if (!$data && !$url) {
            throw new CFHIRException(
                "Impossible to retrieve DocumentReference.content.attachment.data and 
      DocumentReference.content.attachment.url. One of these two values must be present."
            );
        }

        $content = null;
        if ($data) {
            $content = base64_decode($data);
        }

        if ($url && !$content) {
            // Soit le contenu du fichier est dans le message (<Binary>), soit il faut une requÎºte GET pour récupérer le contenu
            // On check dans la valeur de <fullUrl> ou dans l'id de <entry>
            $entry_node                  = $xpath->getNode("//fhir:entry[@id='$url']");
            $entry_node_by_full_url_node = $xpath->getNode("//fhir:fullUrl[@value='$url']/ancestor::*[position()=1]");

            // Récupération du contenu dans la trame
            if ($entry_node && $entry_node->nodeName == "entry") {
                $content_value = $xpath->getAttributeValue("fhir:resource/fhir:Binary/fhir:content", $entry_node);

                if ($content_value) {
                    $content = base64_decode($content_value);
                }
            } elseif ($entry_node_by_full_url_node && $entry_node_by_full_url_node->nodeName == "entry") {
                $content_value = $xpath->getAttributeValue(
                    "fhir:resource/fhir:Binary/fhir:content",
                    $entry_node_by_full_url_node
                );

                if ($content_value) {
                    $content = base64_decode($content_value);
                }
            } // Récupération du binary en lanÎ·ant une autre requÎºte
            else {
                $binary_resource = CFHIRResource::getExterneResource($url, "binary");
                $content         = $this->getContentFromBinaryResource($binary_resource, $url);
            }
        }

        if (!$content) {
            throw new CFHIRException("Impossible to retrieve content of DocumentReference.");
        }

        // Author : prendre un CMediusers ?
        $file->author_id = CMediusers::get()->_id;

        $file->fillFields();
        $file->updateFormFields();
        $file->setObject($object);
        $file->setContent($content);

        if ($msg = $file->store()) {
            throw new CFHIRException("Impossible to store document : $msg");
        }

        return $file;
    }

    /**
     * Get value of content node (content document)
     *
     * @param string $binary_resource binary resource
     * @param string $url             url
     *
     * @return string
     * @throws CFHIRException
     */
    function getContentFromBinaryResource($binary_resource, $url)
    {
        if (!$binary_resource) {
            throw new CFHIRException("Impossible to retrieve data from $url");
        }

        // TODO XDS TOOLKIT : Contenu directement retourné :
        return base64_encode($binary_resource);

        if (strpos($binary_resource, "<") === false) {
            $dom1 = CFHIRResponse::toXML($binary_resource);
            $dom  = new DOMDocument();
            $dom->loadXML($dom1->saveXML());
        } else {
            $dom = new DOMDocument();
            $dom->loadXML($binary_resource);
        }

        $xpath_binary_resource = new CFHIRXPath($dom);
        $content               = $xpath_binary_resource->getAttributeValue("fhir:content", $dom->documentElement);

        if (!$content) {
            throw new CFHIRException("Impossible to retrieve content from $url");
        }

        return self::validBase64($content) ? base64_decode($content) : $content;
    }

    /**
     * Check string if encoded in base64
     *
     * @param string $string string
     *
     * @return bool
     */
    static function validBase64($string)
    {
        $decoded = base64_decode($string, true);

        // Check if there is no invalid character in string
        if (!preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $string)) {
            return false;
        }

        // Decode the string in strict mode and send the response
        if (!base64_decode($string, true)) {
            return false;
        }

        // Encode and compare it to original one
        if (base64_encode($decoded) != $string) {
            return false;
        }

        return true;
    }

    /**
     * Get patient of document
     *
     * @param CFHIRXPath $xpath              xpath
     * @param DOMNode    $node_doc_reference node
     *
     * @return CPatient
     * @throws CFHIRException|Exception
     */
    function getSubject(CFHIRXPath $xpath, DOMNode $node_doc_reference)
    {
        $url = $xpath->getAttributeValue("fhir:subject/fhir:reference", $node_doc_reference);
        if (!$url) {
            throw new CFHIRException("DocumentReference.subject.reference not found. This value must be present.");
        }

        $patient_node         = CFHIRResourcePatient::getNodePatient(
            self::getExterneResource($url, "patient")
        );
        $patient              = CFHIRResourcePatient::mapPatientFromXML(new CPatient(), $patient_node);
        $mb_patient           = CFHIRResourcePatient::patientIsKnow($patient);
        $mb_patient->_ref_url = $url;

        return $mb_patient;
    }

    /**
     * Get externe resource
     *
     * @param string $url           url
     * @param string $resource_type resource type
     *
     * @return bool|string
     * @throws Exception
     */
    static function getExterneResource($url, $resource_type)
    {
        switch ($resource_type) {
            case "binary":
            case "patient":
                $http_client = new CHTTPClient($url . "?_format=application/fhir+json");

                return $http_client->get();
                break;
            default;
        }
    }

    /**
     * Set content attachment field on resource
     *
     * @param CDocumentItem $object object
     *
     * @return CFHIRDataTypeDocumentReferenceContent
     * @throws CMbException
     */
    function addAttachment(CDocumentItem $object)
    {
        $mediaType = "application/pdf";

        $file = null;
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

        return CFHIRDataTypeDocumentReferenceContent::build(
            [
                "attachment" => CFHIRDataTypeAttachment::build(
                    [
                        "contentType" => new CFHIRDataTypeCode($mediaType),
                        "language"    => new CFHIRDataTypeString("fr-FR"),
                        //"data"        => new CFHIRDataTypeBase64Binary(base64_encode(file_get_contents($path))),
                        "title"       => new CFHIRDataTypeString(
                            $object instanceof CCompteRendu ? $object->nom : $object->file_name
                        ),
                        "url"         => new CFHIRDataTypeString($object->_guid),
                        "size"        => new CFHIRDataTypeInteger(
                            $object instanceof CFile ? $object->doc_size : $file->doc_size
                        ),
                    ]
                ),

                "format" => CFHIRDataTypeCoding::build(
                    [
                        "system"  => new CFHIRDataTypeString("urn:oid:1.3.6.1.4.1.19376.1.2.3"),
                        "code"    => new CFHIRDataTypeString("urn:ihe:pcc:apr:handp:2008"),
                        "display" => new CFHIRDataTypeString("Antepartum Record (APR) - History and Physical"),
                    ]
                ),
            ]
        );
    }

    protected function mapMasterIdentifier(): void
    {
        $this->masterIdentifier = $this->object_mapping->mapMasterIdentifier();
    }

    protected function mapStatus(): void
    {
        $this->status = $this->object_mapping->mapStatus();
    }

    protected function mapType(): void
    {
        $this->type = $this->object_mapping->mapType();
    }

    protected function mapCategory(): void
    {
        $this->category = $this->object_mapping->mapCategory();
    }

    protected function mapSubject(): void
    {
        $this->subject = $this->object_mapping->mapSubject();
    }

    protected function mapDate(): void
    {
        $this->date = $this->object_mapping->mapDate();
    }

    protected function mapAuthor(): void
    {
        $this->author = $this->object_mapping->mapAuthor();
    }

    protected function mapRelatesTo(): void
    {
        $this->relatesTo = $this->object_mapping->mapRelatesTo();
    }

    protected function mapDescription(): void
    {
        $this->description = $this->object_mapping->mapDescription();
    }

    protected function mapSecurityLabel(): void
    {
        $this->securityLabel = $this->object_mapping->mapSecurityLabel();
    }

    protected function mapContent(): void
    {
        $this->content = $this->object_mapping->mapContent();
    }

    protected function mapContext(): void
    {
        $this->context = $this->object_mapping->mapContext();
    }

    protected function mapAuthenticator(): void
    {
        $this->authenticator = $this->object_mapping->mapAuthenticator();
    }

    protected function mapCustodian(): void
    {
        $this->custodian = $this->object_mapping->mapCustodian();
    }

    protected function mapDocStatus(): void
    {
        $this->docStatus = $this->object_mapping->mapDocStatus();
    }
}
