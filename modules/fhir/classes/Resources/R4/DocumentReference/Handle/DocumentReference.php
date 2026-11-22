<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Resources\R4\DocumentReference\Handle;

use Exception;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Files\CFile;
use Hospilibre\Files\CFilesCategory;
use Hospilibre\Files\CFileTraceability;
use Hospilibre\Patients\CPatient;
use Hospilibre\PlanningOp\CSejour;
use Hospilibre\System\CSenderHTTP;
use Ox\Core\CMbArray;
use Ox\Core\CMbDT;
use Ox\Core\CMbException;
use Ox\Core\CStoredObject;
use Ox\Interop\Eai\CDomain;
use Ox\Interop\Eai\Manager\Exceptions\FileManagerException;
use Ox\Interop\Eai\Manager\FileManager;
use Ox\Interop\Eai\Repository\PatientRepository;
use Ox\Interop\Eai\Repository\SejourRepository;
use Ox\Interop\Eai\Resolver\FileTargetResolver;
use Ox\Interop\Eai\Tools\TraceabilityTrait;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectHandleInterface;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\DocumentReference\CFHIRDataTypeDocumentReferenceContent;
use Ox\Interop\Fhir\Exception\CFHIRException;
use Ox\Interop\Fhir\Exception\CFHIRExceptionInvalidValue;
use Ox\Interop\Fhir\Exception\CFHIRExceptionNotSupported;
use Ox\Interop\Fhir\Interactions\CFHIRInteraction;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionCreate;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionUpdate;
use Ox\Interop\Fhir\Profiles\CFHIR;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Ox\Interop\Fhir\Resources\R4\DocumentReference\CFHIRResourceDocumentReference;
use Ox\Interop\Fhir\Resources\R4\Encounter\CFHIRResourceEncounter;
use Ox\Interop\Fhir\Resources\R4\Patient\CFHIRResourcePatient;
use Ox\Interop\Fhir\Utilities\Helper\PatientHelper;
use Ox\Interop\Fhir\Utilities\Helper\ResourceHelper;
use Ox\Interop\Fhir\Utilities\Helper\SejourHelper;
use Ox\Interop\Loinc\CLoinc;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * Prise en charge d'une ressource FHIR DocumentReference (R4).
 */
class DocumentReference implements DelegatedObjectHandleInterface
{
    use TraceabilityTrait;

    protected ?CFHIRResourceDocumentReference $resource = null;
    protected ?CSenderHTTP $sender = null;
    protected ?CFileTraceability $traceability = null;
    protected ?string $ipp = null;
    protected ?CFHIRInteraction $interaction = null;
    protected ?string $nda = null;

    /**
     * @param CFHIRResourceDocumentReference $resource
     *
     * @throws CMbException
     * @throws InvalidArgumentException
     * @throws CFHIRException
     * @inheritDoc
     */
    public function handle(CFHIRResource $resource): ?CFHIRResource
    {
        $this->resource    = $resource;
        $this->sender      = $sender = $resource->_sender;
        $this->interaction = $interaction = $resource->getInteraction();

        $isCreate = $interaction instanceof CFHIRInteractionCreate;
        $isUpdate = $interaction instanceof CFHIRInteractionUpdate;
        if (!$isCreate && !$isUpdate) {
            throw new CFHIRExceptionNotSupported('Invalid interaction given');
        }

        $patient = $this->determinePatient();
        $context = $this->determineContext($patient);

        $file = $this->makeFile($patient, $context);

        // Catégorie visée pour le fichier
        $category = $this->determineFileCategory($context);

        // Persistance du fichier via le gestionnaire de fichiers
        try {
            $storedFile = (new FileManager($sender->_configs["define_name"]))
                ->enableTraceability($this->generateTraceability($file->file_date))
                ->setCategory($category)
                ->setTag($sender->_self_tag)
                ->enableLoadMatching(true)
                ->store($file);
        } catch (FileManagerException $fileError) {
            throw CFHIRException::convert($fileError);
        }

        // Intégration des identifiants externes
        $this->handleIdentifiers($storedFile);

        $storedResource = $resource->buildSelf();
        $storedResource->setObject($storedFile);

        return $storedResource;
    }

    /**
     * @return bool
     */
    private function isModeSas(): bool
    {
        return $this->sender->_configs['mode_sas'];
    }

    /**
     * @inheritDoc
     */
    public function getTargetResource(): CFHIRResource
    {
        return new CFHIRResourceDocumentReference();
    }

    /**
     * @inheritDoc
     */
    public function getProfiles(): array
    {
        return [CFHIR::class];
    }

    /**
     * Tente de résoudre le patient au travers de plusieurs recherches.
     *
     * @return CPatient|null
     * @throws CMbException
     */
    private function determinePatient(): ?CPatient
    {
        // On a besoin de la ressource patient FHIR
        $resourcePatient = $this->getResourcePatient();
        if (!$resourcePatient) {
            return null;
        }

        // Contexte de recherche
        $groupId  = CGroups::loadCurrent()->_id;
        $patient  = PatientHelper::primaryMapping($resourcePatient);
        $insList  = [
            PatientHelper::getINS($resourcePatient, PatientHelper::INS_NIR),
            PatientHelper::getINS($resourcePatient, PatientHelper::INS_NIA),
            PatientHelper::getINS($resourcePatient, PatientHelper::INS_TEST),
        ];

        $masterDomain = CDomain::getMasterDomainPatient($groupId);
        $ipp          = $masterDomain->OID
            ? PatientHelper::getIPP($resourcePatient, $masterDomain->OID)
            : null;
        $this->ipp = $ipp;

        // Recherche effective
        return (new PatientRepository($this->sender->_configs['search_patient_strategy']))
            ->withINS(...$insList)
            ->withIPP($ipp, $this->sender->_tag_patient)
            ->withPatientSearched($patient, $groupId)
            ->find();
    }

    /**
     * Recherche la ressource patient contenue dans le DocumentReference.
     *
     * @return CFHIRResourcePatient|null
     */
    private function getResourcePatient(): ?CFHIRResourcePatient
    {
        $resource = $this->resource;

        $referenceType   = $resource->subject->resolveTypeReference();
        $isContained     = $referenceType === $resource->subject::REFERENCE_TYPE_CONTAINED;
        if (!$resource->contained || !$isContained) {
            return null;
        }

        /** @var CFHIRResourcePatient $resourcePatient */
        $resourcePatient = $resource->getContainedOfType(CFHIRResourcePatient::class);

        return $resourcePatient;
    }

    /**
     * Détermine le contexte auquel le fichier sera rattaché.
     *
     * @param CPatient $patient
     *
     * @return CStoredObject|null
     * @throws InvalidArgumentException
     */
    private function determineContext(CPatient $patient): ?CStoredObject
    {
        // En mode SAS, pas de contexte
        if ($this->isModeSas()) {
            return null;
        }

        // Actuellement, seule la recherche de séjour est gérée
        $resourceEncounter = $this->getResourceEncounter();
        if (!$resourceEncounter) {
            return null;
        }

        // NDA
        $domainNda = CDomain::getMasterDomainSejour($this->sender->group_id);
        $nda       = $domainNda->OID ? SejourHelper::getNDA($resourceEncounter, $domainNda->OID) : null;

        // Identifiant OX
        $objectId = ResourceHelper::getOxObjectIdentifier($resourceEncounter, CSejour::class);

        // Date de séjour déduite de la période
        $dateSejour = null;
        $period     = $resourceEncounter->period;
        if ($period && !$period->isNull()) {
            $dateSejour = $period->start ? $period->start->getDatetime() : $period->end->getDatetime();
        }

        $sejourRepository = (new SejourRepository())
            ->setNDA($nda, $this->sender->_tag_sejour)
            ->setPatient($patient)
            ->setDateSejour($dateSejour)
            ->setObjectId($objectId)
            ->setGroupId($this->sender->group_id);
        $sejourRepository->find();

        $objectAttach = $this->sender->_configs["object_attach"] ?? 'CMbObject';

        return (new FileTargetResolver())
            ->setPatient($patient)
            ->setSejourRepository($sejourRepository)
            ->setModeSas($this->isModeSas())
            // ->setId400Category($id400_category) non géré pour l'instant
            ->resolve($this->sender, $objectAttach);
    }

    /**
     * @param CPatient      $patient
     * @param CStoredObject $context
     *
     * @return CFile
     * @throws Exception
     */
    private function makeFile(?CPatient $patient, ?CStoredObject $context): CFile
    {
        $resource        = $this->resource;
        $sender          = $this->sender;
        $resourceContent = reset($resource->content);

        // Vérification de cohérence du contenu
        $this->checkContent($resourceContent);

        // Extraction des données du fichier
        $fileName = null;
        $content  = null;
        $fileType = null;

        $attachment = $resourceContent->attachment;
        if ($attachment) {
            if ($attachment->title) {
                $fileName = $attachment->title->getValue();
            }

            if ($attachment->data) {
                $content = $attachment->data->getDecodedData();
            }

            if ($attachment->contentType) {
                $fileType = $attachment->contentType->getValue();
            }
        }

        // Date du fichier
        $fileDate = CMbDT::dateTime();
        if ($resource->date && !CMbArray::get($sender->_configs, 'creation_date_file_like_treatment')) {
            $fileDate = $resource->date->getDatetime();
        }

        // Construction du CFile
        $file = new CFile();
        if ($context) {
            $file->setObject($context);
        }
        $file->file_name     = $fileName;
        $file->file_type     = $fileType;
        $file->file_date     = $fileDate;
        $file->type_doc_dmp  = $this->getTypeDocDmp();
        $file->setContent($content);

        return $file;
    }

    /**
     * Contrôle la cohérence des données de contenu.
     *
     * @param CFHIRDataTypeDocumentReferenceContent|null $content
     *
     * @return void
     */
    private function checkContent(?CFHIRDataTypeDocumentReferenceContent $content): void
    {
        if (!$content) {
            return;
        }

        $attachment = $content->attachment;
        if (!$attachment) {
            return;
        }

        $resourceSize = $attachment->size;
        $resourceData = $attachment->data;
        if (!$resourceSize || !$resourceData) {
            return;
        }

        $size        = $resourceSize->getValue();
        $decodedData = $resourceData->getDecodedData();
        if ($size === null || $decodedData === null) {
            return;
        }

        // Contrôle de la taille
        if (strlen($decodedData) !== $size) {
            throw new CFHIRExceptionInvalidValue("The size of content of file is not the same that size given");
        }

        // Contrôle de l'empreinte
        if ($attachment->hash) {
            $hash = $attachment->hash->getValue();
            if ($hash !== null && sha1($decodedData) !== $hash) {
                throw new CFHIRExceptionInvalidValue("The hash of content of file is not the same that hash given");
            }
        }
    }

    /**
     * Génère la traçabilité si la configuration est active.
     *
     * @param string $file_date
     *
     * @return CFileTraceability|null
     * @throws Exception
     */
    private function generateTraceability(string $file_date): ?CFileTraceability
    {
        if (!$this->isModeSas()) {
            return null;
        }

        $resourcePatient = $this->getResourcePatient();
        $patient         = PatientHelper::primaryMapping($resourcePatient);
        $traceability    = $this->generateTraceabilityHelper($this->sender, $patient);

        $exchange   = $this->interaction->_ref_fhir_exchange;
        $receivedDt = ($exchange && $exchange->date_production) ? $exchange->date_production : null;

        $traceability->received_datetime = $receivedDt ?: CMbDT::dateTime();
        $traceability->IPP               = $this->ipp;
        $traceability->NDA               = $this->nda;
        $traceability->datetime_object   = $file_date;

        return $traceability;
    }

    /**
     * Détermine la catégorie du fichier.
     *
     * @param CStoredObject|null $object
     *
     * @return CFilesCategory|null
     * @throws Exception
     */
    private function determineFileCategory(?CStoredObject $object): ?CFilesCategory
    {
        $sender = $this->sender;

        /** @var CFilesCategory $filesCategory */
        // Chargement de la catégorie associée à l'expéditeur
        $filesCategory = $sender->getLinkedObjectOfType(CFilesCategory::class);
        if (!$filesCategory) {
            return null;
        }

        $resolvedCategory = null;

        $patientCategoryWanted = $object instanceof CPatient
            && $sender->_configs["id_category_patient"]
            && ($sender->_configs["object_attach"] == "CPatient");

        if ($patientCategoryWanted) {
            $categoryId = $sender->_configs["id_category_patient"];
            $category   = new CFilesCategory();
            $category->load($categoryId);
            if ($category->_id) {
                $resolvedCategory = $category;
            }
        } elseif ($filesCategory->_id && $sender->_configs["associate_category_to_a_file"]) {
            $resolvedCategory = $filesCategory;
        }

        return $resolvedCategory;
    }

    /**
     * @return CFHIRResourceEncounter|null
     */
    private function getResourceEncounter(): ?CFHIRResourceEncounter
    {
        return $this->resource->getContainedOfType(CFHIRResourceEncounter::class);
    }

    /**
     * Recherche le code DMP dans le type de la ressource.
     *
     * @return string|null
     */
    private function getTypeDocDmp(): ?string
    {
        $resource = $this->resource;

        if (!$resource->type) {
            return null;
        }

        $codings = $resource->type->coding;
        if (!$codings) {
            return null;
        }

        foreach ($codings as $coding) {
            if (!ResourceHelper::isLoincSystem($coding->system)) {
                continue;
            }

            $loinc = CLoinc::get($coding->code->getValue());
            if ($loinc->_id) {
                return $loinc->serialize();
            }
        }

        return null;
    }

    /**
     * @param CFile $stored_file
     *
     * @return void
     */
    protected function handleIdentifiers(CFile $stored_file): void
    {
        // Aucune action pour le moment
    }
}
