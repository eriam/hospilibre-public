<?php

/**
 * @package Ox\Interop\Fhir
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Resources\R4\Organization\Mapper;

use Hospilibre\Patients\CExercicePlace;
use Ox\Core\CStoredObject;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectMapperInterface;
use Ox\Interop\Fhir\Contracts\Mapping\R4\OrganizationMappingInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeBoolean;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeAddress;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCoding;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeContactPoint;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;
use Ox\Interop\Fhir\Profiles\CFHIR;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Ox\Interop\Fhir\Resources\R4\Organization\CFHIRResourceOrganization;
use Ox\Interop\Fhir\Resources\ResourceTrait;
use Psr\SimpleCache\InvalidArgumentException;
use ReflectionException;

/**
 * Mappeur FHIR R4 d'un lieu d'exercice vers une ressource Organization.
 */
class Organization implements DelegatedObjectMapperInterface, OrganizationMappingInterface
{
    use ResourceTrait;

    /** @var CExercicePlace */
    protected $object;

    /** @var CFHIRResourceOrganization */
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
        return new CFHIRDataTypeBoolean(true);
    }

    public function mapType(): array
    {
        // Type figé : fournisseur de soins de santé.
        $coding = CFHIRDataTypeCoding::addCoding(
            'urn:oid:2.16.840.1.113883.4.642.1.1128',
            'prov',
            'Healthcare Provider'
        );

        $codeable = CFHIRDataTypeCodeableConcept::addCodeable(
            $coding,
            'An organization that provides healthcare services.'
        );

        return [$codeable];
    }

    public function mapName(): ?CFHIRDataTypeString
    {
        return new CFHIRDataTypeString($this->object->raison_sociale);
    }

    public function mapAlias(): array
    {
        return [new CFHIRDataTypeString($this->object->raison_sociale)];
    }

    /**
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    public function mapTelecom(): array
    {
        // Chaque coordonnée renseignée produit un point de contact.
        $entries = [
            ['system' => 'phone', 'value' => $this->object->tel],
            ['system' => 'phone', 'value' => $this->object->tel2],
            ['system' => 'phone', 'value' => $this->object->fax],
            ['system' => 'email', 'value' => $this->object->email],
        ];

        $telecoms = [];

        foreach ($entries as $entry) {
            if (!$entry['value']) {
                continue;
            }

            $telecoms[] = CFHIRDataTypeContactPoint::build($entry);
        }

        return $telecoms;
    }

    /**
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    public function mapAddress(): array
    {
        $hasAnyPart = $this->object->adresse
            || $this->object->commune
            || $this->object->cp
            || $this->object->pays;

        if (!$hasAnyPart) {
            return [];
        }

        $address = CFHIRDataTypeAddress::build(
            [
                'use'        => new CFHIRDataTypeCode('work'),
                'type'       => new CFHIRDataTypeCode('both'),
                'line'       => new CFHIRDataTypeString($this->object->adresse) ?? null,
                'city'       => new CFHIRDataTypeString($this->object->commune) ?? null,
                'postalCode' => new CFHIRDataTypeString($this->object->cp) ?? null,
                'country'    => new CFHIRDataTypeString($this->object->pays) ?? null,
            ]
        );

        return [$address];
    }

    public function mapPartOf(): ?CFHIRDataTypeReference
    {
        // non implémenté
        return null;
    }

    public function mapContact(): array
    {
        // non implémenté
        return [];
    }

    public function mapEndpoint(): ?CFHIRDataTypeReference
    {
        // non implémenté
        return null;
    }
}
