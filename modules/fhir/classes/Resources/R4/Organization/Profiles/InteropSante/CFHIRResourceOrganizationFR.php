<?php

/**
 * @package Hospilibre\Fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Resources\R4\Organization\Profiles\InteropSante;

use Exception;
use Ox\Core\CStoredObject;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectMapperInterface;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Organization\CFHIRDataTypeOrganizationContact;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeAddress;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeContactPoint;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeIdentifier;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;
use Ox\Interop\Fhir\Event\CFHIREvent;
use Ox\Interop\Fhir\Exception\CFHIRException;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionCreate;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionDelete;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionRead;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionSearch;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionUpdate;
use Ox\Interop\Fhir\Profiles\CFHIRInteropSante;
use Ox\Interop\Fhir\Resources\R4\Organization\CFHIRResourceOrganization;
use Ox\Interop\Fhir\Resources\R4\Organization\Mapper\FrOrganization;
use Ox\Interop\Fhir\Utilities\CCapabilitiesResource;
use Hospilibre\Patients\CExercicePlace;

/**
 * FHIR organization resource
 */
class CFHIRResourceOrganizationFR extends CFHIRResourceOrganization
{
    // constants
    /** @var string */
    public const PROFILE_TYPE = 'FrOrganization';

    /** @var string */
    public const PROFILE_CLASS = CFHIRInteropSante::class;

    /**
     * @param CStoredObject $object
     *
     * @return DelegatedObjectMapperInterface
     */
    protected function setMapperOld(CStoredObject $object): DelegatedObjectMapperInterface
    {
        $mapping_object = FrOrganization::class;

        return new $mapping_object();
    }
}
