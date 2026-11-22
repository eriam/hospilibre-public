<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Resources\R4\Organization\Mapper;

use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectMapperInterface;
use Ox\Interop\Fhir\Contracts\Mapping\R4\OrganizationMappingInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeBoolean;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeAddress;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCoding;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeContactPoint;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeExtension;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeIdentifier;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;
use Ox\Interop\Fhir\Profiles\CFHIRInteropSante;
use Ox\Interop\Fhir\Resources\R4\Organization\CFHIRResourceOrganization;
use Ox\Interop\Fhir\Resources\R4\Organization\Profiles\InteropSante\CFHIRResourceOrganizationFR;
use Ox\Interop\Fhir\Resources\ResourceTrait;
use Hospilibre\Patients\CExercicePlace;
use Psr\SimpleCache\InvalidArgumentException;
use ReflectionException;

/**
 * Mapper FHIR pour le profil FR d'une Organization (InteropSante).
 */
class FrOrganization extends Organization
{
    use ResourceTrait;

    /** @var CExercicePlace */
    protected $object;

    /** @var CFHIRResourceOrganizationFR */
    protected $resource;

    public function mapExtension(): array
    {
        $shortName = CFHIRDataTypeExtension::addExtension(
            'http://interopsante.org/fhir/StructureDefinition/FrOrganizationShortName',
            ['valueString' => $this->object->raison_sociale]
        );

        return [$shortName];
    }

    /**
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    public function mapIdentifier(): array
    {
        $system = 'http://interopsante.org/CodeSystem/fr-v2-0203';
        $use    = 'usual';

        // Table associant chaque valeur métier à son code/libellé FINESS ou SIREN/SIRET.
        $definitions = [
            ['value' => $this->object->finess_juridique, 'code' => 'FINEJ', 'display' => 'FINESS d\'entité juridique'],
            ['value' => $this->object->finess, 'code' => 'FINEG', 'display' => 'FINESS d\'entité géographique'],
            ['value' => $this->object->siren, 'code' => 'SIREN', 'display' => 'Identification de l\'organisation au SIREN'],
            ['value' => $this->object->siret, 'code' => 'SIRET', 'display' => 'Identification de l\'organisation au SIRET'],
        ];

        $identifiers = [];

        foreach ($definitions as $definition) {
            $value = $definition['value'];

            if (!$value) {
                continue;
            }

            $coding    = CFHIRDataTypeCoding::addCoding($system, $definition['code'], $definition['display']);
            $codeable  = CFHIRDataTypeCodeableConcept::addCodeable($coding);

            $identifiers[] = CFHIRDataTypeIdentifier::addIdentifier(
                $this->resource->identifier,
                $value,
                $codeable,
                $use,
                $system
            );
        }

        return $identifiers;
    }

    public function mapType(): array
    {
        // organizationType
        $organizationType = CFHIRDataTypeCoding::addCoding(
            "https://simplifier.net/frenchprofiledfhirar/v2-3307",
            "GROUP",
            "Groupe privé/hospitalier"
        );

        // secteurActiviteRASS
        //$version             = 20201030120000;
        //$user_selected             = true;
        $secteurActiviteRASS = CFHIRDataTypeCoding::addCoding(
            "https://mos.esante.gouv.fr/NOS/TRE_R02-SecteurActivite/FHIR/TRE-R02-SecteurActivite",
            "SA04",
            "Etablissement privé non PSPH"
        );

        // categorieEtablissementRASS
        //$version             = 20210528120000;
        //$user_selected             = true;
        $categorieEtablissementRASS = CFHIRDataTypeCoding::addCoding(
            "https://mos.esante.gouv.fr/NOS/TRE_R66-CategorieEtablissement/FHIR/TRE-R66-CategorieEtablissement",
            "106",
            "Centre hospitalier, ex HÏ„pital local"
        );

        return [
            CFHIRDataTypeCodeableConcept::addCodeable($organizationType, ''),
            CFHIRDataTypeCodeableConcept::addCodeable($secteurActiviteRASS, '', $this->resource->type),
            CFHIRDataTypeCodeableConcept::addCodeable($categorieEtablissementRASS, '', $this->resource->type),
        ];
    }

    public function getProfiles(): array
    {
        return [CFHIRInteropSante::class];
    }
}
