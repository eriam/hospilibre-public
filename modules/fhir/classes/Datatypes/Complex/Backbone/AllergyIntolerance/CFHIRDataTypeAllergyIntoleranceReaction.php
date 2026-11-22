<?php

/**
 * @package Ox\Interop\Fhir\Datatypes\Complex\Backbone\AllergyIntolerance
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Datatypes\Complex\Backbone\AllergyIntolerance;

use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeDateTime;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\CFHIRDataTypeBackboneElement;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeAnnotation;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;

/**
 * Élément « reaction » d'une AllergyIntolerance FHIR : décrit une manifestation
 * clinique observée (substance en cause, symptômes, sévérité, etc.).
 */
class CFHIRDataTypeAllergyIntoleranceReaction extends CFHIRDataTypeBackboneElement
{
    public const NAME = 'AllergyIntolerance.reaction';

    /** @var CFHIRDataTypeCodeableConcept Substance identifiée comme responsable */
    public $substance;

    /** @var CFHIRDataTypeCodeableConcept[] Symptômes ou signes cliniques constatés */
    public $manifestation;

    /** @var CFHIRDataTypeString Description textuelle de l'épisode */
    public $description;

    /** @var CFHIRDataTypeDateTime Date/heure d'apparition */
    public $onset;

    /** @var CFHIRDataTypeCode Sévérité de la réaction */
    public $severity;

    /** @var CFHIRDataTypeCodeableConcept Voie d'exposition à la substance */
    public $exposureRoute;

    /** @var CFHIRDataTypeAnnotation[] Notes complémentaires */
    public $note;
}
