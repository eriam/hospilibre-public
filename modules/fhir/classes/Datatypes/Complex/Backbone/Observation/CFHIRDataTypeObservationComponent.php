<?php

/**
 * @package Ox\Interop\Fhir\Datatypes\Complex\Backbone\Observation
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Datatypes\Complex\Backbone\Observation;

use Ox\Interop\Fhir\Datatypes\Complex\Backbone\CFHIRDataTypeBackboneElement;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeChoice;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeQuantity;

/**
 * Element imbriqué « component » d'une ressource FHIR Observation.
 *
 * Regroupe les sous-mesures d'une observation composite (code, valeur,
 * motif d'absence de donnée, interprétations et plages de référence).
 */
class CFHIRDataTypeObservationComponent extends CFHIRDataTypeBackboneElement
{
    /** @var string Nom qualifié de l'element FHIR. */
    public const NAME = 'Observation.component';

    /** @var CFHIRDataTypeCodeableConcept Code décrivant la sous-mesure. */
    public CFHIRDataTypeCodeableConcept $code;

    /** @var CFHIRDataTypeChoice|null Valeur mesurée (type polymorphe). */
    public ?CFHIRDataTypeChoice $value = null;

    /** @var CFHIRDataTypeCodeableConcept|null Raison de l'absence de valeur. */
    public ?CFHIRDataTypeCodeableConcept $dataAbsentReason = null;

    /** @var CFHIRDataTypeCodeableConcept[] Interprétations qualitatives. */
    public array $interpretation = [];

    /** @var CFHIRDataTypeObservationReferenceRange[] Plages de référence. */
    public array $referenceRange = [];
}
