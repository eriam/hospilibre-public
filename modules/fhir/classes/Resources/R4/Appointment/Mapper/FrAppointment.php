<?php

/**
 * @package Ox\Interop\Fhir
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Resources\R4\Appointment\Mapper;

use Exception;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeExtension;
use Ox\Interop\Fhir\Profiles\CFHIRInteropSante;
use Ox\Interop\Fhir\Resources\R4\Appointment\Profiles\InteropSante\CFHIRResourceAppointmentFR;
use Ox\Interop\Fhir\Resources\R4\Practitioner\Profiles\InteropSante\CFHIRResourcePractitionerFR;
use Ox\Interop\Fhir\Resources\ResourceTrait;
use Hospilibre\Cabinet\CConsultation;

/**
 * Mapper FHIR pour le profil FrAppointment (InteropSante).
 */
class FrAppointment extends Appointment
{
    use ResourceTrait;

    /** @var CConsultation */
    protected $object;

    /** @var CFHIRResourceAppointmentFR */
    protected $resource;

    public function mapExtension(): array
    {
        // Reference vers le praticien operateur du rendez-vous.
        $operator_ref = $this->resource->addReference(
            CFHIRResourcePractitionerFR::class,
            $this->object->loadRefPraticien()
        );

        $extension = CFHIRDataTypeExtension::addExtension(
            'http://interopsante.org/fhir/StructureDefinition/FrAppointmentOperator',
            ['value' => $operator_ref]
        );

        return [$extension];
    }

    /**
     * @throws Exception
     */
    public function mapSpecialty(): array
    {
        $praticien = $this->object->loadRefPraticien();

        // Sans praticien charge, aucune specialite a exposer.
        if (!$praticien || !$praticien->_id) {
            return [];
        }

        $coding = $this->resource->setPractitionerSpecialty($praticien);

        if (empty($coding)) {
            return [];
        }

        return [CFHIRDataTypeCodeableConcept::addCodeable($coding)];
    }

    public function mapSlot(string $resource_class): array
    {
        $references = [];

        foreach ($this->object->loadRefSlots() as $creneau) {
            $references[] = $this->resource->addReference($resource_class, $creneau);
        }

        return $references;
    }

    public function getProfiles(): array
    {
        return [CFHIRInteropSante::class];
    }
}
