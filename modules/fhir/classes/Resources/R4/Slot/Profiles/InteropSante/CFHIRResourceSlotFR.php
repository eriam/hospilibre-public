<?php

/**
 * @package Mediboard\Fhir
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Resources\R4\Slot\Profiles\InteropSante;

use Ox\Core\CStoredObject;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectMapperInterface;
use Ox\Interop\Fhir\Profiles\CFHIRInteropSante;
use Ox\Interop\Fhir\Resources\R4\Slot\CFHIRResourceSlot;
use Ox\Interop\Fhir\Resources\R4\Slot\Mapper\FrSlot;

/**
 * Ressource Slot déclinée pour le profil français InteropSanté.
 */
class CFHIRResourceSlotFR extends CFHIRResourceSlot
{
    /** Identifiant du profil rattaché à cette ressource. */
    public const PROFILE_TYPE = "FrSlot";

    /** Classe de profil FHIR associée. */
    public const PROFILE_CLASS = CFHIRInteropSante::class;

    /**
     * Fournit le mapper délégué chargé de traduire l'objet métier.
     *
     * @param CStoredObject $object Objet source à transformer.
     *
     * @return DelegatedObjectMapperInterface Instance de mapper dédiée au Slot FR.
     */
    protected function setMapperOld(CStoredObject $object): DelegatedObjectMapperInterface
    {
        return new FrSlot();
    }
}
