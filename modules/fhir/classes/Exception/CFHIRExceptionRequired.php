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
 * Exception FHIR levée lorsqu'un élément obligatoire est absent.
 *
 * L'anomalie est classée comme fatale et de type « required »
 * dans le rapport d'opération (OperationOutcome).
 */
class CFHIRExceptionRequired extends CFHIRException
{
    public function __construct(
        string $message,
        int $status_code = Response::HTTP_BAD_REQUEST,
        array $headers = [],
        int $code = 0
    ) {
        // Délégation au constructeur parent pour le message HTTP et les en-têtes.
        parent::__construct($message, $status_code, $headers, $code);

        // Qualification de l'anomalie : élément requis manquant, gravité maximale.
        $this->issueType = CFHIRIssueType::TYPE_REQUIRED;
        $this->severity  = CFHIRIssueSeverity::SEVERITY_FATAL;
    }
}
