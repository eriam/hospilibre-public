<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Resources\R4\Organization\Handle\AnnuaireSante;

use Exception;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeIdentifier;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Ox\Interop\Fhir\Resources\R4\Organization\Profiles\InteropSante\CFHIRResourceOrganizationFR;
use Ox\Interop\Fhir\Resources\ResourceHandleInterface;
use Hospilibre\Patients\CExercicePlace;

class HandleOrganizationExercicePlace implements ResourceHandleInterface
{
    /** @var CExercicePlace $exercice_place */
    private $exercice_place;

    /**
     * @throws Exception
     */
    public function handle(CFHIRResource $resource): void
    {
        $this->exercice_place = new CExercicePlace();

        /** @var CFHIRResourceOrganizationFR $organization_resource */
        $organization_resource = $resource;

        if ($organization_resource->identifier) {
            $this->mapIdentifier($organization_resource->identifier);
        }

        if ($organization_resource->type) {
            $this->mapType($organization_resource->type);
        }

        if ($organization_resource->name) {
            $this->mapName($organization_resource->name);
        }

        if ($organization_resource->telecom) {
            $this->mapTelecom($organization_resource->telecom);
        }

        if ($organization_resource->address) {
            $this->mapAddress($organization_resource->address);
        }

        if ($msg = $this->exercice_place->store()) {
            throw new Exception($msg);
        }
    }

    public function getTargetResource(): CFHIRResource
    {
        return new CFHIRResourceOrganizationFR();
    }

    /**
     * Map property identifier
     */
    protected function mapIdentifier(array $identifiers): void
    {
        /** @var CFHIRDataTypeIdentifier $_identifier */
        foreach ($identifiers as $_identifier) {
            switch ($_identifier->type->coding[0]->system->getValue()) {
                case 'http://interopsante.org/CodeSystem/fr-v2-0203':
                    switch ($_identifier->type->coding[0]->code->getValue()) {
                        case 'IDNST':
                            $this->exercice_place->id_technique = $_identifier->getValue();
                            break;
                        case 'INTRN':
                            // TODO Enregistrer l'idex
                            break;
                        default:
                            break;
                    }
                    break;

                case 'https://mos.esante.gouv.fr/NOS/TRE_G07-TypeIdentifiantStructure/FHIR/TRE-G07-TypeIdentifiantStructure':
                    switch ($_identifier->type->coding[0]->code->getValue()) {
                        case '0':
                            // TODO Enregistrer l'adeli ici
                            break;
                        default:
                            break;
                    }
                    break;
                default:
                    break;
            }
        }
    }

    /**
     * Map property type
     */
    protected function mapType(array $types): void
    {
        //not implemented
    }

    /**
     * Map property name
     */
    protected function mapName(CFHIRDataTypeString $name): void
    {
        $this->exercice_place->raison_sociale = $name->getValue();
    }

    /**
     * Map property telecom
     *
     */
    protected function mapTelecom(array $telecoms): void
    {
        dump($telecoms);
    }

    /**
     * Map property address
     */
    protected function mapAddress(array $addresses): void
    {
        dump($addresses);
    }
}
