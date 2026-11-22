<?php

/**
 * @package Ox\Interop\Fhir\Datatypes\Complex\Backbone\Bundle
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Datatypes\Complex\Backbone\Bundle;

use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeDecimal;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\CFHIRDataTypeBackboneElement;

/**
 * Element de recherche associe a une entree de Bundle FHIR (Bundle.entry.search).
 */
class CFHIRDataTypeBundleSearch extends CFHIRDataTypeBackboneElement
{
    /** @var string Nom qualifie de l'element FHIR */
    public const NAME = 'Bundle.entry.search';

    /** @var CFHIRDataTypeCode Mode de recherche (match, include, outcome) */
    public $mode;

    /** @var CFHIRDataTypeDecimal Score de pertinence du resultat */
    public $score;
}
