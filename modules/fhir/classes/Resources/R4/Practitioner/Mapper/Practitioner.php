<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Resources\R4\Practitioner\Mapper;

use Ox\Core\CStoredObject;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectMapperInterface;
use Ox\Interop\Fhir\Contracts\Mapping\R4\PractitionerMappingInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeBoolean;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeDate;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Practitioner\CFHIRDataTypePractitionerQualification;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeAddress;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCoding;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeContactPoint;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeHumanName;
use Ox\Interop\Fhir\Profiles\CFHIR;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Ox\Interop\Fhir\Resources\R4\Practitioner\CFHIRResourcePractitioner;
use Ox\Interop\Fhir\Resources\ResourceTrait;
use Hospilibre\Patients\CMedecin;
use Psr\SimpleCache\InvalidArgumentException;
use ReflectionException;

/**
 * Mapper transformant un CMedecin en ressource FHIR Practitioner (R4).
 */
class Practitioner implements DelegatedObjectMapperInterface, PractitionerMappingInterface
{
    use ResourceTrait;

    /** @var CMedecin */
    protected $object;

    /** @var CFHIRResourcePractitioner */
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
    }

    public function mapExtension(): array
    {
        return [];
    }

    public function mapIdentifier(): array
    {
        return [];
    }

    public function mapActive(): ?CFHIRDataTypeBoolean
    {
        return new CFHIRDataTypeBoolean($this->object->actif);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function mapName(): array
    {
        return CFHIRDataTypeHumanName::addName(
            $this->object->nom,
            $this->object->prenom,
            'usual',
            $this->object->_view
        );
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function mapTelecom(): array
    {
        // Chaque coordonnee renseignee sur le medecin devient un ContactPoint FHIR.
        $medecin = $this->object;

        $sources = [
            ['system' => 'phone', 'value' => $medecin->tel],
            ['system' => 'phone', 'value' => $medecin->tel_autre],
            ['system' => 'fax',   'value' => $medecin->fax],
            ['system' => 'phone', 'value' => $medecin->portable],
            ['system' => 'email', 'value' => $medecin->email],
        ];

        $contactPoints = [];
        foreach ($sources as $source) {
            if (!$source['value']) {
                continue;
            }

            $contactPoints[] = CFHIRDataTypeContactPoint::build(
                [
                    "system" => $source['system'],
                    "value"  => $source['value'],
                ]
            );
        }

        return $contactPoints;
    }

    /**
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    public function mapAddress(): array
    {
        $medecin = $this->object;

        // Sans aucun champ d'adresse exploitable, on ne produit rien.
        if (!$medecin->adresse && !$medecin->ville && !$medecin->cp) {
            return [];
        }

        $address = CFHIRDataTypeAddress::build(
            [
                'use'        => 'work',
                'type'       => 'postal',
                'line'       => preg_split('/[\r\n]+/', $medecin->adresse) ?? null,
                'city'       => $medecin->ville ?? null,
                'postalCode' => $medecin->cp ?? null,
            ]
        );

        return [$address];
    }

    public function mapGender(): ?CFHIRDataTypeCode
    {
        $gender = $this->resource->formatGender($this->object->sexe);

        return new CFHIRDataTypeCode($gender);
    }

    public function mapBirthDate(): ?CFHIRDataTypeDate
    {
        return null;
    }

    public function mapPhoto(): array
    {
        return [];
    }

    /**
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    public function mapQualification(): array
    {
        // Qualification fixe : Doctor of Medicine.
        $text   = 'Doctor of Medicine';
        $coding = CFHIRDataTypeCoding::addCoding(
            'urn:oid:2.16.840.1.113883.18.220',
            'MD',
            'Doctor of Medicine'
        );

        $qualification = CFHIRDataTypePractitionerQualification::build(
            [
                'identifier' => null,
                'code'       => CFHIRDataTypeCodeableConcept::addCodeable($coding, $text),
                'period'     => null,
                'issuer'     => null,
            ]
        );

        return [$qualification];
    }

    public function mapCommunication(): array
    {
        // Langue de communication fixee au francais (France).
        $coding = CFHIRDataTypeCoding::addCoding('urn:ietf:bcp:47', 'fr-FR', 'French (France)');
        $text   = 'FranÎ·ais de France';

        return [
            CFHIRDataTypeCodeableConcept::addCodeable($coding, $text),
        ];
    }
}
