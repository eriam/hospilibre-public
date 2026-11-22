<?php

/**
 * @package Ox\Interop\Fhir
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Resources\R4\DocumentReference\Mapper;

use Exception;
use Ox\Core\CStoredObject;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectMapperInterface;
use Ox\Interop\Fhir\Contracts\Mapping\R4\DocumentReferenceMappingInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeInstant;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\DocumentReference\CFHIRDataTypeDocumentReferenceContent;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\DocumentReference\CFHIRDataTypeDocumentReferenceContext;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeAttachment;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeIdentifier;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypePeriod;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;
use Ox\Interop\Fhir\Profiles\CFHIR;
use Ox\Interop\Fhir\Resources\CFHIRDomainResource;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Ox\Interop\Fhir\Resources\R4\Encounter\CFHIRResourceEncounter;
use Ox\Interop\Fhir\Resources\R4\Patient\CFHIRResourcePatient;
use Ox\Interop\Fhir\Resources\R4\Practitioner\CFHIRResourcePractitioner;
use Ox\Interop\InteropResources\valueset\CANSValueSet;
use Hospilibre\Files\CDocumentItem;
use Hospilibre\Files\CFile;
use Hospilibre\Patients\CCorrespondantPatient;
use Hospilibre\Patients\CMedecin;
use Hospilibre\Patients\CPatient;
use Hospilibre\PlanningOp\COperation;
use Hospilibre\PlanningOp\CSejour;
use Hospilibre\System\CNote;

/**
 * Mapper FHIR R4 générique pour la ressource DocumentReference.
 */
class DocumentReference implements DocumentReferenceMappingInterface, DelegatedObjectMapperInterface
{
    /** @var CFHIRResource|CFHIRDomainResource */
    protected $resource;

    /** @var CDocumentItem */
    protected $document;

    /** @var CStoredObject */
    protected $target;

    /**
     * @inheritDoc
     */
    public function getProfiles(): array
    {
        return [CFhir::class];
    }

    /**
     * @inheritDoc
     */
    public function setResource(CFHIRResource $resource, CStoredObject $object): void
    {
        $this->resource = $resource;
        $this->document = $object;
    }

    /**
     * @inheritDoc
     */
    public function initialize(): void
    {
        $this->target = $this->document->loadTargetObject();
    }

    public function mapMasterIdentifier(): ?CFHIRDataTypeIdentifier
    {
        return new CFHIRDataTypeIdentifier($this->document->getUuid());
    }

    /**
     * @inheritDoc
     */
    public function mapExtension(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function mapIdentifier(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function mapStatus(): ?CFHIRDataTypeCode
    {
        return new CFHIRDataTypeCode('current');
    }

    /**
     * @inheritDoc
     */
    public function mapDocStatus(): ?CFHIRDataTypeCode
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function mapType(): ?CFHIRDataTypeCodeableConcept
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function mapCategory(): array
    {
        // Sans type de document DMP, aucune catégorie n'est produite.
        if (!$this->document->type_doc_dmp) {
            return [];
        }

        [$system, $code] = explode('^', $this->document->type_doc_dmp);

        $codes = CANSValueSet::getTypeCode($code);
        if (!$codes) {
            return [];
        }

        return [CFHIRDataTypeCodeableConcept::fromValues($codes)];
    }

    /**
     * @inheritDoc
     */
    public function mapSubject(): ?CFHIRDataTypeReference
    {
        $patient = $this->document->getFromStore(CPatient::class);

        // Repli sur le patient indexable quand le store ne fournit rien d'exploitable.
        if (!$patient || !$patient->_id) {
            $patient = $this->document->getIndexablePatient();

            if (!$patient->_id) {
                return null;
            }
        }

        return $this->resource->addReference(CFHIRResourcePatient::class, $patient);
    }

    /**
     * @inheritDoc
     */
    public function mapDate(): ?CFHIRDataTypeInstant
    {
        return new CFHIRDataTypeInstant($this->document->_file_date);
    }

    /**
     * @inheritDoc
     */
    public function mapAuthor(): array
    {
        $authors = [];

        foreach ([CCorrespondantPatient::class, CMedecin::class] as $authorClass) {
            $stored = $this->document->getFromStore($authorClass);
            if ($stored) {
                $authors[] = $this->resource->addReference(CFHIRResourcePractitioner::class, $stored);
            }
        }

        $refAuthor = $this->document->loadRefAuthor();
        if ($refAuthor) {
            $authors[] = $this->resource->addReference(CFHIRResourcePractitioner::class, $refAuthor);
        }

        return $authors;
    }

    /**
     * @inheritDoc
     */
    public function mapAuthenticator(): ?CFHIRDataTypeReference
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function mapCustodian(): ?CFHIRDataTypeReference
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function mapRelatesTo(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function mapDescription(): ?CFHIRDataTypeString
    {
        /** @var CNote $note */
        $note = $this->document->loadLastBackRef('notes');

        if (!$note || !$note->_id) {
            return null;
        }

        return new CFHIRDataTypeString($note->text ?: $note->libelle);
    }

    /**
     * @inheritDoc
     */
    public function mapSecurityLabel(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function mapContent(): array
    {
        // Toute erreur d'accès au binaire équivaut à une absence de contenu.
        try {
            $content = $this->document->getBinaryContent(true, false);
        } catch (Exception $e) {
            return [];
        }

        if (!$content) {
            return [];
        }

        $isFile = $this->document instanceof CFile;
        $contentType = $isFile ? $this->document->file_type : "application/pdf";
        $title       = $isFile ? $this->document->file_name : $this->document->nom;

        $referenceContent = CFHIRDataTypeDocumentReferenceContent::build(
            [
                'attachment' => CFHIRDataTypeAttachment::build(
                    [
                        'data'        => base64_encode($content),
                        'size'        => strlen($content),
                        'hash'        => sha1($content),
                        'title'       => $title,
                        'creation'    => $this->document->_file_date,
                        'contentType' => $contentType,
                    ]
                ),
            ]
        );

        return [$referenceContent];
    }

    /**
     * @inheritDoc
     */
    public function mapContext(): ?CFHIRDataTypeDocumentReferenceContext
    {
        $context = new CFHIRDataTypeDocumentReferenceContext();

        $target = $this->target;

        // Une intervention est rattachée au séjour qui la porte.
        if ($target instanceof COperation) {
            $target = $target->loadRefSejour();
        }

        if ($target instanceof CSejour) {
            $context->encounter = $this->resource->addReference(CFHIRResourceEncounter::class, $target);
            $context->period    = CFHIRDataTypePeriod::from($target->entree, $target->sortie);
        }

        return $context->isNull() ? null : $context;
    }
}
