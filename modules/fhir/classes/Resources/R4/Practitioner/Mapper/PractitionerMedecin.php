<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Resources\R4\Practitioner\Mapper;

use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeBoolean;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeDate;
use Ox\Interop\Fhir\Resources\R4\Practitioner\Profiles\ANS\CFHIRResourcePractitionerRASS;
use Ox\Interop\Fhir\Resources\ResourceTrait;
use Hospilibre\Patients\CMedecin;

class PractitionerMedecin extends Practitioner
{
    use ResourceTrait;

    /** @var CMedecin */
    protected $object;

    /** @var CFHIRResourcePractitionerRASS */
    protected $resource;
}
