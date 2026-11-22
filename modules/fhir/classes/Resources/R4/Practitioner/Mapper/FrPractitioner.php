<?php

/**
 * @package Ox\Interop\Fhir
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Resources\R4\Practitioner\Mapper;

use Exception;
use Ox\Core\CMbArray;
use Ox\Interop\Eai\CDomain;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeDate;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Patient\CFHIRDataTypePatientContact;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Practitioner\CFHIRDataTypePractitionerQualification;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeAddress;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCoding;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeContactPoint;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeExtension;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeHumanName;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeIdentifier;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;
use Ox\Interop\Fhir\Profiles\CFHIRInteropSante;
use Ox\Interop\Fhir\Resources\R4\Organization\Profiles\InteropSante\CFHIRResourceOrganizationFR;
use Ox\Interop\Fhir\Resources\R4\Patient\Profiles\InteropSante\CFHIRResourcePatientFR;
use Ox\Interop\Fhir\Resources\R4\Practitioner\Mapper\Practitioner;
use Ox\Interop\Fhir\Resources\R4\Practitioner\Profiles\InteropSante\CFHIRResourcePractitionerFR;
use Ox\Interop\Fhir\Resources\R4\RelatedPerson\Profiles\InteropSante\CFHIRResourceRelatedPersonFR;
use Ox\Interop\Fhir\Resources\ResourceTrait;
use Hospilibre\Patients\CCorrespondantPatient;
use Hospilibre\Patients\CExercicePlace;
use Hospilibre\Patients\CIdentityProofType;
use Hospilibre\Patients\CINSPatient;
use Hospilibre\Patients\CMedecin;
use Hospilibre\Patients\CMedecinExercicePlace;
use Hospilibre\Patients\CPatient;
use Hospilibre\Patients\CSourceIdentite;
use Psr\SimpleCache\InvalidArgumentException;
use ReflectionException;

/**
 * Mapper Practitioner adapte au profil francais InteropSante.
 */
class FrPractitioner extends Practitioner
{
    use ResourceTrait;

    /** @var CMedecin */
    protected $object;

    /** @var CFHIRResourcePractitionerFR */
    protected $resource;

    /**
     * Construit les identifiants ADELI et RPPS du praticien.
     *
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function mapIdentifier(): array
    {
        // Systeme d'oid partage par les deux identifiants nationaux
        $system_oid   = 'urn:oid:1.2.250.1.71.4.2.1';
        $code_system  = 'http://interopsante.org/fhir/CodeSystem/fr-v2-0203';

        $identifier = $this->resource->identifier;

        /** @var CMedecinExercicePlace[] $exercice_places */
        $exercice_places = $this->object->getMedecinExercicePlaces();
        $adeli_place     = array_unique(CMbArray::pluck($exercice_places, "adeli"))[0];

        // Identifiant ADELI : issu d'un lieu d'exercice ou porte par le medecin
        if ($adeli_place || $this->object->adeli) {
            $adeli_coding   = CFHIRDataTypeCoding::addCoding($code_system, 'ADELI', 'NÂ° ADELI');
            $adeli_concept  = CFHIRDataTypeCodeableConcept::addCodeable($adeli_coding, 'NÂ° ADELI');

            $identifier = CFHIRDataTypeIdentifier::addIdentifier(
                $this->resource->identifier,
                $adeli_place ?? $this->object->adeli,
                $adeli_concept,
                'official',
                $system_oid
            );
        }

        // Identifiant RPPS
        if ($this->object->rpps) {
            $rpps_coding  = CFHIRDataTypeCoding::addCoding($code_system, 'RPPS', 'NÂ° RPPS');
            $rpps_concept = CFHIRDataTypeCodeableConcept::addCodeable($rpps_coding, 'NÂ° RPPS');

            $identifier = CFHIRDataTypeIdentifier::addIdentifier(
                $this->resource->identifier,
                $this->object->rpps,
                $rpps_concept,
                'official',
                $system_oid
            );
        }

        return $identifier;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function mapName(): array
    {
        // TODO PASSER EN FrHumanName
        return CFHIRDataTypeHumanName::addName(
            $this->object->nom,
            $this->object->prenom,
            'usual',
            $this->object->_view
        );
    }

    /**
     * Assemble les moyens de contact declares sur la fiche du medecin.
     *
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function mapTelecom(): array
    {
        // TODO PASSER EN FrContactPoint

        // Chaque paire (systeme FHIR, champ de la fiche) donne lieu a un contact
        // si la valeur correspondante est renseignee.
        $contacts = [
            ['phone', $this->object->tel],
            ['phone', $this->object->tel_autre],
            ['fax', $this->object->fax],
            ['portable', $this->object->portable],
            ['email', $this->object->email],
        ];

        $telecoms = [];
        foreach ($contacts as [$system, $value]) {
            if (!$value) {
                continue;
            }

            $telecoms[] = CFHIRDataTypeContactPoint::build(
                [
                    "system" => $system,
                    "value"  => $value,
                ]
            );
        }

        return $telecoms;
    }

    /**
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    public function mapAddress(): array
    {
        // TODO PASSER EN FrAddress

        // Sans aucune composante d'adresse renseignee, rien a produire
        if (!$this->object->adresse && !$this->object->ville && !$this->object->cp) {
            return [];
        }

        return [
            CFHIRDataTypeAddress::build(
                [
                    'use'        => 'work',
                    'type'       => 'postal',
                    'line'       => preg_split('/[\r\n]+/', $this->object->adresse) ?? null,
                    'city'       => $this->object->ville ?? null,
                    'postalCode' => $this->object->cp ?? null,
                ]
            ),
        ];
    }

    /**
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    public function mapQualification(): array
    {
        // TODO Mapper avec les spécialités franÎ·aise
        return parent::mapQualification();
    }

    public function getProfiles(): array
    {
        return [CFHIRInteropSante::class];
    }
}
