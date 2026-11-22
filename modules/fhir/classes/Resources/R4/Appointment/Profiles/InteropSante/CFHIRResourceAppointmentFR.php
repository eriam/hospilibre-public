<?php

/**
 * @package Mediboard\Fhir
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Resources\R4\Appointment\Profiles\InteropSante;

use Ox\Core\CStoredObject;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectMapperInterface;
use Ox\Interop\Fhir\Profiles\CFHIRInteropSante;
use Ox\Interop\Fhir\Resources\R4\Appointment\CFHIRResourceAppointment;
use Ox\Interop\Fhir\Resources\R4\Appointment\Mapper\FrAppointment;
use Ox\Interop\Fhir\Resources\R4\Slot\Profiles\InteropSante\CFHIRResourceSlotFR;

/**
 * Ressource Appointment au profil français InteropSanté.
 */
class CFHIRResourceAppointmentFR extends CFHIRResourceAppointment
{
    /** @var string */
    public const PROFILE_TYPE = 'FrAppointment';

    /** @var string */
    public const PROFILE_CLASS = CFHIRInteropSante::class;

    /**
     * Instancie le mapper dédié au profil français.
     *
     * @param CStoredObject $object
     *
     * @return DelegatedObjectMapperInterface
     */
    protected function setMapperOld(CStoredObject $object): DelegatedObjectMapperInterface
    {
        return new FrAppointment();
    }

    /**
     * Renseigne la propriété slot depuis le mapping de l'objet.
     */
    protected function mapSlot(string $resource_class = CFHIRResourceSlotFR::class): void
    {
        $this->slot = $this->object_mapping->mapSlot($resource_class);
    }
}
