<?php

/**
 * @package Mediboard\Fhir\Exception
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Exception;

use Ox\Interop\Fhir\ValueSet\CFHIRIssueSeverity;
use Ox\Interop\Fhir\ValueSet\CFHIRIssueType;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exception FHIR signalant qu'une opération ou ressource n'est pas prise en charge.
 *
 * Positionne systématiquement le type d'incident sur « not-supported »
 * et la sévérité sur « fatal ».
 */
class CFHIRExceptionNotSupported extends CFHIRException
{
    public function __construct(
        string $message,
        int $status_code = Response::HTTP_BAD_REQUEST,
        array $headers = [],
        int $code = 0
    ) {
        // Délègue à l'exception FHIR de base pour le message et la réponse HTTP.
        parent::__construct($message, $status_code, $headers, $code);

        // Qualification de l'incident propre à ce cas d'erreur.
        $this->severity  = CFHIRIssueSeverity::SEVERITY_FATAL;
        $this->issueType = CFHIRIssueType::TYPE_NOT_SUPPORTED;
    }
}
