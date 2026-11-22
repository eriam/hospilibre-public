<?php

/**
 * @package Hospilibre\fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Resources\R4\AllergyIntolerance;

use Exception;
use Ox\Core\CStoredObject;
use Ox\Interop\Fhir\Contracts\Resources\ResourceAllergieIntoleranceInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeDateTime;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\AllergyIntolerance\CFHIRDataTypeAllergyIntoleranceReaction;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeAge;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeAnnotation;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeChoice;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypePeriod;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;
use Ox\Interop\Fhir\Event\CFHIREvent;
use Ox\Interop\Fhir\Exception\CFHIRException;
use Ox\Interop\Fhir\Exception\CFHIRExceptionNotFound;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionCreate;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionDelete;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionRead;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionSearch;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionUpdate;
use Ox\Interop\Fhir\Resources\CFHIRDomainResource;
use Ox\Interop\Fhir\Resources\R4\Encounter\CFHIRResourceEncounter;
use Ox\Interop\Fhir\Resources\R4\Patient\CFHIRResourcePatient;
use Ox\Interop\Fhir\Resources\R4\Practitioner\CFHIRResourcePractitioner;
use Ox\Interop\Fhir\Utilities\CCapabilitiesResource;
use Hospilibre\Patients\CAntecedent;
use Hospilibre\Patients\CDossierMedical;
use Hospilibre\Patients\CDossierTiers;
use Hospilibre\Patients\CPatient;
use Hospilibre\PlanningOp\CSejour;
use Hospilibre\Ucum\Ucum;

/**
 * Description
 *
 * @see http://hl7.org/fhir/allergyintolerance.html
 */
class CFHIRResourceAllergyIntolerance extends CFHIRDomainResource implements ResourceAllergieIntoleranceInterface
{
    /** @var string */
    public const CRITICALITY_LOW = 'low';
    /** @var string */
    public const CRITICALITY_HIGH = 'high';

    // constants
    /** @var string */
    public const RESOURCE_TYPE = 'AllergyIntolerance';

    // attributes
    /** @var CFHIRDataTypeCodeableConcept */
    public $clinicalStatus;

    /** @var CFHIRDataTypeCodeableConcept */
    public $verificationStatus;

    /** @var CFHIRDataTypeCode */
    public $type;

    /** @var CFHIRDataTypeCode */
    public $category;

    /** @var CFHIRDataTypeCode */
    public $criticality;

    /** @var CFHIRDataTypeCodeableConcept */
    public $code;

    /** @var CFHIRDataTypeReference */
    public $patient;

    /** @var CFHIRDataTypeReference */
    public $encounter;

    /** @var CFHIRDataTypeChoice */
    public $onset;

    /** @var CFHIRDataTypeDateTime */
    public $recordedDate;

    /** @var CFHIRDataTypeReference */
    public $recorder;

    /** @var CFHIRDataTypeReference */
    public $asserter;

    /** @var CFHIRDataTypeDateTime */
    public $lastOccurrence;

    /** @var CFHIRDataTypeAnnotation[] */
    public $note;

    /** @var CFHIRDataTypeAllergyIntoleranceReaction[] */
    public $reaction;

    /** @var CAntecedent */
    protected $object;

    /**
     * @inheritdoc
     */
    public function getClass(): ?string
    {
        return CAntecedent::class;
    }

    /**
     * @return CCapabilitiesResource
     */
    public function generateCapabilities(): CCapabilitiesResource
    {
        return (parent::generateCapabilities())
            ->addInteractions(
                [
                    CFHIRInteractionCreate::NAME,
                    CFHIRInteractionRead::NAME,
                    CFHIRInteractionSearch::NAME,
                    CFHIRInteractionUpdate::NAME,
                    CFHIRInteractionDelete::NAME,
                ]
            );
    }

    /**
     * @param CStoredObject $object
     */
    protected function map(CStoredObject $object): void
    {
        if (!$object || !$object->_id) {
            return;
        }

        // Only antecedent type allergy
        if ($object->type !== 'alle') {
            throw new CFHIRExceptionNotFound('The allergyIntolerance asked was not found');
        }

        parent::map($object);

        // clinicalStatus
        $this->mapClinicalStatus();

        // verificationStatus
        $this->mapVerificationStatus();

        // type
        $this->mapType();

        // category
        $this->mapCategory();

        // criticality
        $this->mapCriticality();

        // code
        $this->mapCode();

        // patient
        $this->mapPatient();

        // encounter
        $this->mapEncounter();

        // onset
        $this->mapOnset();

        // recordedDate
        $this->mapRecordedDate();

        // recorder
        $this->mapRecorder();

        // asserter
        $this->mapAsserter();

        // lastOccurrence
        $this->mapLastOccurrence();

        // note
        $this->mapNote();

        // reaction
        $this->mapReaction();
    }

    /**
     * @param CStoredObject $object
     *
     * @return array
     * @throws \Ox\Core\Api\Exceptions\ApiRequestException
     */
    protected function getWhere(CStoredObject $object, array &$ljoin): array
    {
        $where = parent::getWhere($object, $ljoin);

        // only antecedent type allergy
        $where['type'] = $object->getDS()->prepare('= ?', 'alle');

        return $where;
    }

    /**
     * Map property clinicalStatus
     */
    protected function mapClinicalStatus(): void
    {
        $code    = 'active';
        $display = 'Active';

        // is resolved
        if ($this->object->date_fin) {
            $code    = 'resolved';
            $display = 'Resolved';
        }

        // Absence d'allergie
        if ($this->object->absence) {
            $code    = 'inactive';
            $display = 'Inactive';
        }

        // only confirmed allergy is added
        $this->clinicalStatus = $this->addCodeableConcepts(
            $this->addCoding(
                'http://terminology.hl7.org/CodeSystem/allergyintolerance-clinical',
                $code,
                $display
            ),
        );
    }

    /**
     * Map property verificationStatus
     */
    protected function mapVerificationStatus(): void
    {
        // only confirmed allergy is added
        $this->verificationStatus = $this->addCodeableConcepts(
            $this->addCoding(
                'http://terminology.hl7.org/CodeSystem/allergyintolerance-verification',
                'confirmed',
                'Confirmed'
            ),
        );
    }

    /**
     * Map property type (only 'allergy' is supported)
     */
    protected function mapType(): void
    {
        $this->type = new CFHIRDataTypeCode('allergy');
    }

    /**
     * Map property
     */
    protected function mapCategory(): void
    {
        // not implemented
    }

    /**
     * Map property criticality
     */
    protected function mapCriticality(): void
    {
        $criticality = ($this->object->majeur || $this->object->important) ? self::CRITICALITY_HIGH : self::CRITICALITY_LOW;

        $this->criticality = new CFHIRDataTypeCode($criticality);
    }

    /**
     * Map property code
     */
    protected function mapCode(): void
    {
        $code = null;

        // CIM 10 // todo a utiliser uniquement dans le cadre du profile fr
        // if ($this->object->extractCim10Codes()) {
        //     $cim_10_code = reset($this->object->_codes_cim10);
        //     /** @var CCodeCIM10 $cim_10_detail */
        //     $cim_10_detail = $this->object->_codes_cim10_detail[$cim_10_code];
        //     $code          = [
        //         'system'  => 'http://hl7.org/fhir/sid/icd-10',
        //         'code'    => $cim_10_code,
        //         'display' => $cim_10_detail->libelle,
        //     ];
        // }

        // Snomed
        if (!$code && $this->object->loadRefsCodesSnomed()) {
            $code_snomed = reset($this->object->_ref_codes_snomed);
            $code          = [
                'system'  => 'http://snomed.info/sct',
                'code'    => $code_snomed->code,
                'display' => $code_snomed->libelle,
            ];
        }

        // Loinc
        if (!$code && $this->object->loadRefsCodesLoinc()) {
            $code_loinc = reset($this->object->_ref_codes_loinc);
            $code          = [
                'system'  => 'http://loinc.org',
                'code'    => $code_loinc->code,
                'display' => $code_loinc->libelle_fr,
            ];
        }

        if ($code) {
            $this->code = $this->addCodeableConcepts(
                $this->addCoding(
                    $code['system'],
                    $code['code'],
                    $code['display']
                )
            );
        }
    }

    /**
     * Map property patient
     */
    protected function mapPatient(): void
    {
        if (!$patient = $this->getPatient()) {
            throw new CFHIRExceptionNotFound('Patient not found');
        }

        $this->patient = $this->addReference(CFHIRResourcePatient::class, $patient);
    }

    /**
     * Map property encounter
     */
    protected function mapEncounter(): void
    {
        if (!$sejour = $this->getSejour()) {
            return;
        }

        $this->addReference(CFHIRResourceEncounter::class, $sejour);
    }

    /**
     * Map property onset
     */
    protected function mapOnset(): void
    {
        if (!$this->object->date) {
            return;
        }

        $start_year = substr($this->object->date, 0, -6);
        if ($is_period = $this->object->date && $this->object->date_fin) {
            $end_year = substr($this->object->date_fin, 0, -6);

            $start_datetime = $start_year . str_replace('00', '01', substr($this->object->date, 4));
            $end_datetime   = $end_year . str_replace('00', '01', substr($this->object->date_fin, 4));

            $data        = [
                'start' => $start_datetime,
                'end'   => $end_datetime,
            ];
            $this->onset = new CFHIRDataTypeChoice(CFHIRDataTypePeriod::class, $data);

            return;
        }

        // Only Year ==> use OnsetAge (UCUM system)
        if ($is_ucum = preg_match('#^\d{4}-00-00$#', $this->object->date)) {
            $data        = [
                'value'   => $start_year,
                'system'  => Ucum::CODE_SYSTEM,
                'code'    => 'a',
                'unit' => 'year',
            ];
            $this->onset = new CFHIRDataTypeChoice(CFHIRDataTypeAge::class, $data);

            return;
        }

        $this->onset = new CFHIRDataTypeChoice(CFHIRDataTypeDateTime::class, $this->object->date);
    }

    /**
     * Map property recordedDate
     */
    protected function mapRecordedDate(): void
    {
        $this->recordedDate = new CFHIRDataTypeDateTime($this->object->creation_date);
    }

    /**
     * Map property recorder
     */
    protected function mapRecorder(): void
    {
        $reference_class  = null;
        $reference_object = null;

        // Reference Patient From AppFine
        if ($dossier_tiers = $this->getDossierTiers()) {
            if ($dossier_tiers->name === CDossierTiers::NAME_APPFINE) {
                $reference_class  = CFHIRResourcePatient::class;
                $reference_object = $this->getPatient();
            }
        }

        // Reference Mediusers
        if (!$reference_class) {
            $reference_class  = CFHIRResourcePractitioner::class;
            $mediusers        = $this->object->loadRefOwner();
            $reference_object = $mediusers && $mediusers->_id ? $mediusers : null;
        }

        if ($reference_class && $reference_object) {
            $this->recorder = $this->addReference($reference_class, $reference_object);
        }
    }

    /**
     * Map property asserter
     */
    protected function mapAsserter(): void
    {
        // Reference Patient From AppFine
        if ($dossier_tiers = $this->getDossierTiers()) {
            if ($dossier_tiers->name === CDossierTiers::NAME_APPFINE) {
                $reference_class  = CFHIRResourcePatient::class;
                $reference_object = $this->getPatient();

                $this->asserter = $this->addReference($reference_class, $reference_object);
            }
        }
    }

    /**
     * Map property lastOccurrence
     */
    protected function mapLastOccurrence(): void
    {
        // not implemented, you need use
    }

    /**
     * Map property note
     */
    protected function mapNote(): void
    {
        if (!$comment = ($this->object->comment ?: $this->object->rques)) {
            return;
        }

        $this->note[] = CFHIRDataTypeAnnotation::build(
            ['text' => $comment]
        );
    }

    /**
     * Map property reaction
     */
    protected function mapReaction(): void
    {
        // not implemented, need reaction coded in snomed
    }

    public function build(CStoredObject $object, CFHIREvent $event): void
    {
        parent::build($object, $event);

        if (!$object instanceof CAntecedent || $object->type !== 'alle') {
            throw new CFHIRException("Object is not an allergyIntolerance");
        }

        $this->mapFrom($object);
    }

    /**
     * @return CDossierMedical|null
     * @throws Exception
     */
    protected function getDossierMedical(): ?CDossierMedical
    {
        if (!$this->object || !$this->object->dossier_medical_id) {
            return null;
        }

        $dossier_medical = $this->object->_ref_dossier_medical ?: $this->object->loadRefDossierMedical();

        return $dossier_medical && $dossier_medical->_id ? $dossier_medical : null;
    }

    /**
     * @return CDossierTiers|null
     * @throws Exception
     */
    protected function getDossierTiers(): ?CDossierTiers
    {
        if (!$this->object || !$this->object->dossier_tiers_id) {
            return null;
        }

        $dossier_tiers = $this->object->_ref_dossier_tiers ?: $this->object->loadRefDossierTiers();

        return $dossier_tiers && $dossier_tiers->_id ? $dossier_tiers : null;
    }

    /**
     * @return CPatient|null
     * @throws Exception
     */
    protected function getPatient(): ?CPatient
    {
        $context = null;

        // Patient From Dossier Tiers
        if ($dossier_tiers = $this->getDossierTiers()) {
            $dossier_tiers->loadRefObject();
            if (($context = $dossier_tiers->_ref_object) instanceof CSejour) {
                $context = $context->loadRefPatient();
            }
        }

        // Patient from Dossier Medical
        if ($dossier_medical = $this->getDossierMedical()) {
            $context = $dossier_medical->loadRefObject();
            if (!$context instanceof CPatient) {
                $context = $context->loadRefPatient();
            }
        }

        return ($context && $context->_id) ? $context : null;
    }

    /**
     * @return CSejour|null
     * @throws Exception
     */
    protected function getSejour(): ?CSejour
    {
        $context = null;

        // Sejour From Dossier Tiers
        if ($dossier_tiers = $this->getDossierTiers()) {
            if ($dossier_tiers->object_class === 'CSejour') {
                $dossier_tiers->loadRefObject();
                $context = $dossier_tiers->_ref_object;
            }
        }

        // Sejour From Dossier Medical
        if ($dossier_medical = $this->getDossierMedical()) {
            $context = $dossier_medical->loadRefObject();
            if (!$context instanceof CSejour) {
                $context = null;
            }
        }

        return ($context && $context->_id) ? $context : null;
    }
}
