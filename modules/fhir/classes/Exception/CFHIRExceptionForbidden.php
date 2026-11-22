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
 * Exception FHIR levée lorsqu'un accès à une ressource est refusé (HTTP 403).
 */
class CFHIRExceptionForbidden extends CFHIRException
{
    /**
     * @param string $message     Libellé de l'erreur retourné au client.
     * @param int    $status_code Code de statut HTTP associé (403 par défaut).
     * @param array  $headers     En-têtes HTTP additionnels.
     * @param int    $code        Code d'erreur interne.
     */
    public function __construct(
        string $message = "You are not authorized to access at this resource",
        int $status_code = Response::HTTP_FORBIDDEN,
        array $headers = [],
        int $code = 0
    ) {
        // On délègue l'initialisation de base à l'exception FHIR parente.
        parent::__construct($message, $status_code, $headers, $code);

        // Puis on qualifie précisément la nature FHIR de l'incident.
        $this->severity  = CFHIRIssueSeverity::SEVERITY_FATAL;
        $this->issueType = CFHIRIssueType::TYPE_FORBIDDEN;
    }
}
