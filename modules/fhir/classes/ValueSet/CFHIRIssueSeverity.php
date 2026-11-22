<?php

/**
 * @package Mediboard\Fhir\ValueSet
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\ValueSet;

/**
 * Niveaux de gravité d'un OperationOutcome FHIR.
 *
 * Reprend les valeurs du ValueSet issue-severity défini par HL7.
 *
 * @see http://hl7.org/fhir/valueset-issue-severity.html
 * @package Ox\Interop\Fhir\ValueSet
 */
class CFHIRIssueSeverity
{
    /** Problème bloquant : le traitement ne peut pas se poursuivre. @var string */
    public const SEVERITY_FATAL = 'fatal';

    /** Erreur ayant empêché l'aboutissement de l'opération. @var string */
    public const SEVERITY_ERROR = 'error';

    /** Avertissement sans blocage de l'opération. @var string */
    public const SEVERITY_WARNING = 'warning';

    /** Simple information complémentaire. @var string */
    public const SEVERITY_INFORMATION = 'information';
}
