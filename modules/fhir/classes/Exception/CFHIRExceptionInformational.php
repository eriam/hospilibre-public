<?php

/**
 * Exception FHIR de niveau informatif.
 *
 * @package Mediboard\Fhir\Exception
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Exception;

use Ox\Interop\Fhir\ValueSet\CFHIRIssueType;
use Symfony\Component\HttpFoundation\Response;

class CFHIRExceptionInformational extends CFHIRException
{
    /** @var int Code applicatif : ressource non encore prise en charge. */
    public const CODE_RESOURCE_NOT_SUPPORTED_NOW = 10001;

    public function __construct(
        string $message,
        int $status_code = Response::HTTP_INTERNAL_SERVER_ERROR,
        array $headers = [],
        int $code = 0
    ) {
        parent::__construct($message, $status_code, $headers, $code);

        // Toute exception de cette classe est marquée comme informative.
        $this->issueType = CFHIRIssueType::TYPE_INFORMATIONAL;
    }

    /**
     * Fabrique une exception signalant l'absence de configuration d'émetteur HTTP.
     *
     * @return static
     */
    public static function invalidSenderConfigs(): self
    {
        return new self('The configuration for sender HTTP is not set');
    }
}
