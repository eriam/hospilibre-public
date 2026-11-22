<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Resources\R4\Schedule\Mapper;

use Exception;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCoding;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeExtension;
use Ox\Interop\Fhir\Profiles\CFHIRInteropSante;
use Ox\Interop\Fhir\Resources\R4\Practitioner\CFHIRResourcePractitioner;
use Ox\Interop\Fhir\Resources\R4\Practitioner\Profiles\InteropSante\CFHIRResourcePractitionerFR;
use Ox\Interop\Fhir\Resources\R4\Schedule\Profiles\InteropSante\CFHIRResourceScheduleFR;
use Ox\Interop\Fhir\Resources\ResourceTrait;
use Hospilibre\Cabinet\CPlageconsult;
use Hospilibre\Patients\CMedecin;

/**
 * Mapper Schedule au profil FR InteropSante.
 */
class FrSchedule extends Schedule
{
    use ResourceTrait;

    /** @var CPlageconsult */
    protected $object;

    /** @var CFHIRResourceScheduleFR */
    protected $resource;

    public function mapExtension(): array
    {
        // Une plage verrouillee est consideree comme indisponible.
        $isLocked = $this->object->locked;
        $typeCode = $isLocked ? 'busy-unavailable' : 'free';
        $typeText = $isLocked ? 'Indisponibilité' : 'Disponibilité';

        $typeCoding = CFHIRDataTypeCoding::addCoding(
            'http://interopsante.org/fhir/CodeSystem/fr-schedule-type',
            $typeCode,
            $typeText
        );

        $innerExtensions = [
            CFHIRDataTypeExtension::addExtension('type', ['value' => $typeCoding]),
            // TODO Extension en erreur à corriger
            /*$this->formatExtension(
                'rrule',
                [
                    'extension' => $this->loadRuleExtensions()
                ]
            ),*/
            CFHIRDataTypeExtension::addExtension('start', ['valueDateTime' => $this->object->debut]),
            CFHIRDataTypeExtension::addExtension('end', ['valueDateTime' => $this->object->fin]),
            CFHIRDataTypeExtension::addExtension(
                'identifier',
                ['value' => $this->resource->identifier[0]]
            ),
        ];

        return [
            CFHIRDataTypeExtension::addExtension(
                'http://interopsante.org/fhir/StructureDefinition/FrScheduleAvailabiltyTime',
                $innerExtensions
            ),
        ];
    }

    /**
     * @throws Exception
     */
    public function mapSpecialty(): array
    {
        $practitioner = $this->object->loadRefChir();

        if (!$practitioner || !$practitioner->_id) {
            return [];
        }

        $coding = $this->resource->setPractitionerSpecialty($practitioner);

        return [CFHIRDataTypeCodeableConcept::addCodeable($coding)];
    }

    /**
     * Map property actor
     * @throws Exception
     */
    public function mapActor(): array
    {
        // Les actors sont à dynamiser en fonction du besoin, pour le projectathon nous avons échange un practitioner
        $practitioner = $this->object->loadRefChir();

        $medecin       = new CMedecin();
        $medecin->rpps = $practitioner->rpps;
        $medecin->loadMatchingObject();

        return $this->resource->addReference(CFHIRResourcePractitionerFR::class, $medecin);
    }

    /**
     * @return CFHIRDataTypeExtension[]
     */
    private function loadRuleExtensions(): array
    {
        $until    = $this->object->fin;
        $interval = $this->object->_freq_minutes;
        $count    = 60 / $interval;

        $freqCoding = CFHIRDataTypeCoding::addCoding(
            'https://www.ietf.org/rfc/rfc2445',
            'MINUTELY',
            'Par minute'
        );

        $ruleParts = [
            CFHIRDataTypeExtension::addExtension('freq', ['value' => $freqCoding]),
            CFHIRDataTypeExtension::addExtension('until', ['valueDateTime' => $until]),
            CFHIRDataTypeExtension::addExtension('count', ['valueInteger' => $count]),
            CFHIRDataTypeExtension::addExtension('interval', ['valueInteger' => $interval]),
        ];

        return [
            CFHIRDataTypeExtension::addExtension('rrule', $ruleParts),
        ];
    }

    public function getProfiles(): array
    {
        return [CFHIRInteropSante::class];
    }
}
