<?php

/**
 * @package Mediboard\Fhir
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Resources\R4\Schedule\Profiles\InteropSante;

use Ox\Core\CStoredObject;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectMapperInterface;
use Ox\Interop\Fhir\Profiles\CFHIRInteropSante;
use Ox\Interop\Fhir\Resources\R4\Schedule\CFHIRResourceSchedule;
use Ox\Interop\Fhir\Resources\R4\Schedule\Mapper\FrSchedule;

/**
 * Ressource FHIR Schedule declinee pour le profil InteropSante (France).
 */
class CFHIRResourceScheduleFR extends CFHIRResourceSchedule
{
    /** @var string */
    public const PROFILE_TYPE = 'FrSchedule';

    /** @var string */
    public const PROFILE_CLASS = CFHIRInteropSante::class;

    /**
     * Fournit le mapper delegue historique associe a cette ressource.
     *
     * @param CStoredObject $object Objet metier source
     *
     * @return DelegatedObjectMapperInterface
     */
    protected function setMapperOld(CStoredObject $object): DelegatedObjectMapperInterface
    {
        return new FrSchedule();
    }
}
