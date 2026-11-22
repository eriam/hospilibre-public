<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Resources\R4\Device\Mapper;

use Ox\Interop\Fhir\Profiles\CFHIRPHD;
use Ox\Interop\Fhir\Resources\ResourceTrait;

/**
 * Mapper du dispositif FHIR de type PHD (Personal Health Device).
 */
class PhdDevice extends Device
{
    use ResourceTrait;

    /**
     * Liste des profils FHIR associés à ce dispositif.
     *
     * @return array
     */
    public function getProfiles(): array
    {
        return [
            CFHIRPHD::class,
        ];
    }
}
