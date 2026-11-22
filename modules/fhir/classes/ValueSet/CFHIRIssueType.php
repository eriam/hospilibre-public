<?php

/**
 * @package Mediboard\Fhir\ValueSet
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\ValueSet;

/**
 * Enumération des types d'incident (issue type) FHIR.
 *
 * Chaque constante correspond à un code du ValueSet standard décrit ici :
 * http://hl7.org/fhir/valueset-issue-type.html
 *
 * @package Ox\Interop\FHIR\ValueSet
 */
class CFHIRIssueType
{
    /** Incident inattendu de type exception. @var string */
    public const TYPE_EXCEPTION = 'exception';

    /** Ressource introuvable. @var string */
    public const TYPE_NOT_FOUND = 'not-found';

    /** Message purement informatif. @var string */
    public const TYPE_INFORMATIONAL = 'informational';

    /** Accès refusé. @var string */
    public const TYPE_FORBIDDEN = 'forbidden';

    /** Fonctionnalité non prise en charge. @var string */
    public const TYPE_NOT_SUPPORTED = 'not-supported';

    /** Élément obligatoire manquant. @var string */
    public const TYPE_REQUIRED = 'required';

    /** Valeur fournie invalide. @var string */
    public const TYPE_INVALID_VALUE = 'value';
}
