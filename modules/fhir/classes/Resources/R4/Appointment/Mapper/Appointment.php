<?php

/**
 * @package Hospilibre
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Resources\R4\Appointment\Mapper;

use Exception;
use Ox\Core\CAppUI;
use Ox\Core\CStoredObject;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectMapperInterface;
use Ox\Interop\Fhir\Contracts\Mapping\R4\AppointmentMappingInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeDateTime;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeInstant;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypePositiveInt;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeUnsignedInt;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Appointment\CFHIRDataTypeAppointmentParticipant;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCoding;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypePeriod;
use Ox\Interop\Fhir\Profiles\CFHIR;
use Ox\Interop\Fhir\Resources\R4\Appointment\CFHIRResourceAppointment;
use Ox\Interop\Fhir\Resources\R4\Patient\CFHIRResourcePatient;
use Ox\Interop\Fhir\Resources\R4\Practitioner\CFHIRResourcePractitioner;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Ox\Interop\Fhir\Resources\R4\PractitionerRole\CFHIRResourcePractitionerRole;
use Ox\Interop\Fhir\Resources\ResourceTrait;
use Hospilibre\Cabinet\CConsultation;
use Hospilibre\Patients\CMedecin;
use Psr\SimpleCache\InvalidArgumentException;
use ReflectionException;

/**
 * Traduction d'une consultation Hospilibre vers la ressource FHIR Appointment (R4).
 */
class Appointment implements DelegatedObjectMapperInterface, AppointmentMappingInterface
{
    use ResourceTrait;

    /** @var CConsultation */
    protected $object;

    /** @var CFHIRResourceAppointment */
    protected $resource;

    public function setResource(CFHIRResource $resource, CStoredObject $object): void
    {
        $this->resource = $resource;
        $this->object   = $object;
    }

    public function getProfiles(): array
    {
        return [CFHIR::class];
    }

    /**
     * @inheritDoc
     */
    public function initialize(): void
    {
        $this->object->loadRefPlageConsult();
    }

    public function mapExtension(): array
    {
        return [];
    }

    public function mapIdentifier(): array
    {
        return [];
    }

    public function mapStatus(): ?CFHIRDataTypeCode
    {
        $consultation = $this->object;

        // Consultation annulée : soit le patient ne s'est pas présenté, soit annulation classique.
        if ($consultation->annule) {
            $absence = $consultation->motif_annulation && $consultation->motif_annulation == 'not_arrived';

            return new CFHIRDataTypeCode($absence ? 'noshow' : 'cancelled');
        }

        // Correspondance entre l'état chronologique interne et le vocabulaire FHIR.
        switch ($consultation->chrono) {
            case CConsultation::DEMANDE:
                return new CFHIRDataTypeCode('proposed');

            case CConsultation::PLANIFIE:
                return new CFHIRDataTypeCode('booked');

            case CConsultation::PATIENT_ARRIVE:
            case CConsultation::EN_COURS:
                return new CFHIRDataTypeCode('arrived');

            case CConsultation::TERMINE:
                return new CFHIRDataTypeCode('fulfilled');

            default:
                return null;
        }
    }

    public function mapCancelationReason(): ?CFHIRDataTypeCodeableConcept
    {
        $consultation = $this->object;

        if (!$consultation->annule || !$consultation->motif_annulation) {
            return null;
        }

        $system = 'urn:oid:2.16.840.1.113883.4.642.3.1381';

        switch ($consultation->motif_annulation) {
            case 'not_arrived':
                $code    = '';
                $display = '';
                break;

            case 'by_patient':
                $code    = 'pat';
                $display = 'Patient';
                break;

            default:
                $code    = 'other';
                $display = 'Other';
        }

        $coding = CFHIRDataTypeCoding::addCoding($system, $code, $display);

        return CFHIRDataTypeCodeableConcept::addCodeable($coding, $display);
    }

    public function mapServiceCategory(): array
    {
        $coding = CFHIRDataTypeCoding::addCoding(
            'http://terminology.hl7.org/CodeSystem/service-category',
            '35',
            'Hospital'
        );

        return [CFHIRDataTypeCodeableConcept::addCodeable($coding, 'Hospital')];
    }

    /**
     * @throws Exception
     */
    public function mapServiceType(): array
    {
        if (!$this->object->categorie_id) {
            return [];
        }

        $categorie = $this->object->loadRefCategorie();

        $coding = CFHIRDataTypeCoding::addCoding(
            'urn:oid:' . CAppUI::conf('mb_oid'),
            $categorie->_id,
            $categorie->nom_categorie
        );

        return CFHIRDataTypeCodeableConcept::addCodeable($coding, null, []);
    }

    public function mapSpecialty(): array
    {
        // non implémenté
        return [];
    }

    public function mapAppointmentType(): ?CFHIRDataTypeCodeableConcept
    {
        // non implémenté
        return null;
    }

    public function mapReasonCode(): array
    {
        // non implémenté
        return [];
    }

    public function mapReasonReference(): array
    {
        // non implémenté
        return [];
    }

    public function mapPriority(): ?CFHIRDataTypeUnsignedInt
    {
        // non implémenté
        return null;
    }

    public function mapDescription(): ?CFHIRDataTypeString
    {
        return new CFHIRDataTypeString($this->object->motif);
    }

    public function mapSupportingInformation(): array
    {
        // non implémenté
        return [];
    }

    public function mapStart(): ?CFHIRDataTypeInstant
    {
        return new CFHIRDataTypeInstant($this->object->_datetime);
    }

    public function mapEnd(): ?CFHIRDataTypeInstant
    {
        return new CFHIRDataTypeInstant($this->object->_date_fin);
    }

    public function mapMinutesDuration(): ?CFHIRDataTypePositiveInt
    {
        return new CFHIRDataTypePositiveInt($this->object->_duree);
    }

    /**
     * @throws Exception
     */
    public function mapSlot(string $resource_class): array
    {
        $slots = [];

        foreach ($this->object->loadRefSlots() as $slot) {
            $slots[] = $this->resource->addReference($resource_class, $slot);
        }

        return $slots;
    }

    public function mapCreated(): ?CFHIRDataTypeDateTime
    {
        return new CFHIRDataTypeDateTime($this->object->_date);
    }

    public function mapComment(): ?CFHIRDataTypeString
    {
        return new CFHIRDataTypeString($this->object->rques);
    }

    public function mapPatientInstruction(): ?CFHIRDataTypeString
    {
        // non implémenté
        return null;
    }

    public function mapBasedOn(): array
    {
        // non implémenté
        return [];
    }

    /**
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function mapParticipant(): array
    {
        $consultation = $this->object;
        $participants = [];

        // Un patient annulé de son fait (ou absent) est considéré comme ayant décliné.
        $patient_declines = $consultation->annule
            && ($consultation->motif_annulation == 'by_patient' || $consultation->motif_annulation == 'not_arrived');
        $status_patient = new CFHIRDataTypeCode($patient_declines ? 'declined' : 'accepted');

        // Un motif « other » est imputé au praticien.
        $practitioner_declines = $consultation->annule && $consultation->motif_annulation == 'other';
        $status_practitioner   = new CFHIRDataTypeCode($practitioner_declines ? 'declined' : 'accepted');

        $practitionerRole = $consultation->loadRefPraticien();

        if ($practitionerRole->_id) {
            // PractitionerRole
            $role_participant           = new CFHIRDataTypeAppointmentParticipant();
            $role_participant->actor     = $this->resource->addReference(
                CFHIRResourcePractitionerRole::class,
                $practitionerRole
            );
            $role_participant->required = new CFHIRDataTypeCode('required');
            $role_participant->status   = new CFHIRDataTypeCode('needs-action');

            $participants[] = $role_participant;
        }

        $practitioner       = new CMedecin();
        $practitioner->rpps = $practitionerRole->rpps;
        $practitioner->loadMatchingObject();

        if ($practitioner->_id) {
            // Practitioner
            $practitioner_participant       = new CFHIRDataTypeAppointmentParticipant();
            $practitioner_participant->type = CFHIRDataTypeCodeableConcept::addCodeable(
                CFHIRDataTypeCoding::addCoding('urn:oid:2.16.840.1.113883.4.642.3.250', 'ADM', 'admitter'),
                'Praticien',
                []
            );
            $practitioner_participant->actor    = $this->resource->addReference(
                CFHIRResourcePractitioner::class,
                $practitioner
            );
            $practitioner_participant->required = new CFHIRDataTypeCode('required');
            $practitioner_participant->status   = $status_practitioner;

            $participants[] = $practitioner_participant;
        }

        $patient = $consultation->loadRefPatient();

        if ($patient->_id) {
            // Patient
            $patient_participant           = new CFHIRDataTypeAppointmentParticipant();
            $patient_participant->actor    = $this->resource->addReference(CFHIRResourcePatient::class, $patient);
            $patient_participant->required = new CFHIRDataTypeCode('required');
            $patient_participant->status   = $status_patient;

            $participants[] = $patient_participant;
        }

        return $participants;
    }

    /**
     * @throws ReflectionException
     * @throws InvalidArgumentException
     * @throws Exception
     */
    public function mapRequestedPeriod(): array
    {
        $formatted = CFHIRDataTypeInstant::formatPeriod(
            CFHIR::getTimeUtc($this->object->_datetime, false),
            CFHIR::getTimeUtc($this->object->_date_fin, false)
        );

        return [CFHIRDataTypePeriod::build($formatted)];
    }
}
