<?php

/**
 * @package Ox\Interop\Fhir
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Resources\R4\Slot\Mapper;

use Exception;
use Hospilibre\Cabinet\CSlot;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;
use Ox\Interop\Fhir\Profiles\CFHIRInteropSante;
use Ox\Interop\Fhir\Resources\R4\Schedule\Profiles\InteropSante\CFHIRResourceScheduleFR;
use Ox\Interop\Fhir\Resources\R4\Slot\Profiles\InteropSante\CFHIRResourceSlotFR;
use Ox\Interop\Fhir\Resources\ResourceTrait;

/**
 * Mapper du profil français FrSlot (InteropSanté) pour la ressource FHIR Slot.
 */
class FrSlot extends Slot
{
    use ResourceTrait;

    /** @var CSlot */
    protected $object;

    /** @var CFHIRResourceSlotFR */
    protected $resource;

    /**
     * Construit la référence vers le Schedule (plage de consultation) associé.
     */
    public function mapSchedule(): ?CFHIRDataTypeReference
    {
        $plage = $this->object->loadRefPlageconsult();

        return $this->resource->addReference(CFHIRResourceScheduleFR::class, $plage);
    }

    /**
     * Renvoie la spécialité du praticien de la plage sous forme de CodeableConcept.
     *
     * @throws Exception
     */
    public function mapSpecialty(): array
    {
        $chir = $this->object->loadRefPlageconsult()->loadRefChir();

        // Sans praticien identifié, aucune spécialité à exposer.
        if (!$chir || !$chir->_id) {
            return [];
        }

        $coding = $this->resource->setPractitionerSpecialty($chir);

        return [CFHIRDataTypeCodeableConcept::addCodeable($coding)];
    }

    /**
     * Liste des profils FHIR portés par ce mapper.
     */
    public function getProfiles(): array
    {
        return [CFHIRInteropSante::class];
    }
}
