<?php

/**
 * @package Hospilibre\Fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Resources\R4\Practitioner\Profiles\InteropSante;

use Ox\Core\CStoredObject;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectMapperInterface;
use Ox\Interop\Fhir\Profiles\CFHIRInteropSante;
use Ox\Interop\Fhir\Resources\R4\Patient\Mapper\FrPractitioner;
use Ox\Interop\Fhir\Resources\R4\Practitioner\CFHIRResourcePractitioner;
use Ox\Interop\Fhir\Resources\R4\Practitioner\PractitionerInterface;
use Ox\Interop\Fhir\Resources\R4\Practitioner\PractitionerMappingInterface;

/**
 * FHIR practitioner resource
 */
class CFHIRResourcePractitionerFR extends CFHIRResourcePractitioner
{
    /** @var string */
    public const PROFILE_TYPE = 'FrPractitioner';

    /** @var string */
    public const PROFILE_CLASS = CFHIRInteropSante::class;

    /** @var FrPractitioner */
    protected $object_mapping;

    /**
     * @param CStoredObject $object
     *
     * @return DelegatedObjectMapperInterface
     */
    protected function setMapperOld(CStoredObject $object): DelegatedObjectMapperInterface
    {
        $mapping_object = FrPractitioner::class;

        return new $mapping_object();
    }
}
