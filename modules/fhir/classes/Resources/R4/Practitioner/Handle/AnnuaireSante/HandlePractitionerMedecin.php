<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Resources\R4\Practitioner\Handle\AnnuaireSante;

use Exception;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeBoolean;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeContactPoint;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeHumanName;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeIdentifier;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Ox\Interop\Fhir\Resources\R4\Organization\Profiles\InteropSante\CFHIRResourceOrganizationFR;
use Ox\Interop\Fhir\Resources\R4\Practitioner\Profiles\InteropSante\CFHIRResourcePractitionerFR;
use Ox\Interop\Fhir\Resources\ResourceHandleInterface;
use Hospilibre\Patients\CExercicePlace;
use Hospilibre\Patients\CMedecin;

class HandlePractitionerMedecin implements ResourceHandleInterface
{
    /** @var CMedecin $medecin */
    private $medecin;

    /** @var CExercicePlace $exercice_place */
    private $exercice_place;

    /**
     * @throws Exception
     */
    public function handle(CFHIRResource $resource): void
    {
        $this->medecin = new CMedecin();

        /** @var CFHIRResourcePractitionerFR $practitioner_resource */
        $practitioner_resource = $resource;

        if ($practitioner_resource->extension) {
            $this->mapExtension($practitioner_resource->extension);
        }

        if ($practitioner_resource->identifier) {
            $this->mapIdentifier($practitioner_resource->identifier);
        }

        if ($practitioner_resource->name) {
            $this->mapName($practitioner_resource->name);
        }

        if ($practitioner_resource->telecom) {
            $this->mapTelecom($practitioner_resource->telecom);
        }

        if ($practitioner_resource->address) {
            $this->mapAddress($practitioner_resource->address);
        }
        /*if ($msg = $this->medecin->store()) {
            throw new Exception($msg);
        }*/
    }

    public function getTargetResource(): CFHIRResource
    {
        return new CFHIRResourcePractitionerFR();
    }

    /**
     * Map property name
     */
    protected function mapExtension(array $extensions): void
    {
        foreach ($extensions as $_extension) {
            dump($_extension->extension);

            switch ($_extension->url->getValue()) {
                case 'https://apifhir.annuaire.sante.fr/ws-sync/exposed/structuredefinition/mailboxMSS':

                    //dump($_extension->extension[0]->value->getValue());
                    // Créer une adresse mssanté rattaché au prat avec la valeur ici
                    break;
                default:
                    break;
            }
        }
    }

    /**
     * Map property active
     */
    protected function mapActive(CFHIRDataTypeBoolean $active): void
    {
        $this->medecin->actif = $active->getValue();
    }

    /**
     * Map property identifier
     */
    protected function mapIdentifier(array $identifiers): void
    {
        /** @var CFHIRDataTypeIdentifier $_identifier */
        foreach ($identifiers as $_identifier) {
            switch ($_identifier->type->coding[0]->system->getValue()) {
                case 'http://interopsante.org/CodeSystem/v2-0203':
                    switch ($_identifier->type->coding[0]->code->getValue()) {
                        case 'RPPS':
                            $this->medecin->rpps = $_identifier->value->getValue();
                            break;
                        case 'ADELI':
                            // TODO Enregistrer l'idex
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
     * Map property name
     */
    protected function mapName(array $names): void
    {
        /** @var CFHIRDataTypeHumanName $_name */
        foreach ($names as $_name) {
            $this->medecin->nom    = $_name->family;
            $this->medecin->prenom = $_name->given;
            $this->medecin->titre  = $_name->prefix[0]->getValue();
        }
    }

    /**
     * Map property telecom
     *
     */
    protected function mapTelecom(array $telecoms): void
    {
        /** @var CFHIRDataTypeContactPoint $_telecom */
        foreach ($telecoms as $_telecom) {
            switch ($_telecom->system->getValue()) {
                case 'phone':
                    $this->medecin->tel = $_telecom->value;
                    break;

                case 'fax':
                    $this->medecin->fax = $_telecom->value;
                    break;

                case 'email':
                    $this->medecin->email = $_telecom->value;
                    break;

                default:
                    break;
            }
        }
    }

    /**
     * Map property address
     */
    protected function mapAddress(array $addresses): void
    {
        // Implement an CExercicePlace
    }

    /**
     * Map property address
     */
    protected function mapQualification(array $addresses): void
    {
        // Implement a CSavoirFaire
    }

    /**
     * Map property language
     */
    protected function mapLanguage(array $addresses): void
    {
        // not implemented
    }
}
