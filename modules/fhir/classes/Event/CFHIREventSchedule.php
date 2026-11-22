<?php

/**
 * @package Mediboard\fhir
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Event;

use Ox\Core\CMbObject;
use Ox\Interop\Fhir\Resources\Appointment\CFHIRResourceAppointment;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Ox\Interop\Fhir\Resources\R4\Schedule\CFHIRResourceSchedule;

/**
 * Évènement FHIR dédié à la ressource Schedule.
 *
 * @package Ox\Interop\Fhir\Event
 */
class CFHIREventSchedule extends CFHIREvent
{
    /**
     * Initialise l'évènement en tant que transaction.
     */
    public function __construct()
    {
        parent::__construct();

        // Aucun code particulier associé pour l'instant.
        $this->code = "";
        $this->type = "transaction";
    }

    /**
     * Fabrique la ressource Schedule à partir de l'objet fourni.
     *
     * @param CMbObject $object Objet source
     *
     * @return CFHIRResourceAppointment
     * @see parent::build()
     */
    public function build(CMbObject $object): CFHIRResource
    {
        // Génération de la ressource Schedule à partir de l'objet métier.
        $resource = new CFHIRResourceSchedule();
        $resource->build($object, $this);

        return $resource;
    }
}
