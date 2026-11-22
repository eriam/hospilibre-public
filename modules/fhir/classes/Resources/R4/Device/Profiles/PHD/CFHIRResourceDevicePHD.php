<?php

/**
 * @package Hospilibre\Fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Resources\R4\Device\Profiles\PHD;

use Ox\Interop\Fhir\Profiles\CFHIRPHD;
use Ox\Interop\Fhir\Resources\R4\Device\CFHIRResourceDevice;

/**
 * FHIR PhdDevice resource
 */
class CFHIRResourceDevicePHD extends CFHIRResourceDevice
{
    // constants
    /** @var string */
    public const PROFILE_TYPE = 'PhdDevice';

    /** @var string */
    public const PROFILE_CLASS = CFHIRPHD::class;
}
