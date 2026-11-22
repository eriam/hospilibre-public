<?php

/**
 * @package Hospilibre\Fhir\Request\Api
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Request\Api;

use Ox\Core\Api\Request\IRequestParameter;
use Ox\Core\CMbArray;
use Symfony\Component\HttpFoundation\Request;

class CRequestFormats implements IRequestParameter
{
    /** @var string */
    public const CONTENT_TYPE_XML = "application/fhir+xml";

    /** @var string */
    public const CONTENT_TYPE_JSON = "application/fhir+json";

    /** @var string */
    public const KEY_RESOURCE_CONTENT_TYPE = 'resource_content_type';

    /** @var Request */
    private $request;

    /** @var string */
    private $format;

    /**
     * Détermine le format de la ressource à partir de la requête HTTP.
     *
     * @param Request $request Requête courante
     */
    public function __construct(Request $request)
    {
        $this->request = $request;

        // On privilégie le paramètre _format, sinon le premier type accepté.
        $requested = $request->get("_format") ?? CMbArray::get($request->getAcceptableContentTypes(), 0);

        // Repli sur le XML lorsque le format n'est pas reconnu.
        $this->format = self::getFormatSupported($requested) ?? self::CONTENT_TYPE_XML;

        $request->attributes->set(self::KEY_RESOURCE_CONTENT_TYPE, $this->format);
    }

    /**
     * Retourne le format retenu pour la ressource.
     *
     * @return string
     */
    public function getFormat(): string
    {
        return $this->format;
    }

    /**
     * Indique si le format retenu est du JSON.
     *
     * @return bool
     */
    public function isFormatJSON(): bool
    {
        return $this->getFormat() === self::CONTENT_TYPE_JSON;
    }

    /**
     * Indique si le format retenu est du XML.
     *
     * @return bool
     */
    public function isFormatXML(): bool
    {
        return $this->getFormat() === self::CONTENT_TYPE_XML;
    }

    /**
     * Indique si l'en-tête Content-Type correspond à du JSON.
     *
     * @return bool
     */
    public function isContentTypeJSON(): bool
    {
        $header = $this->request->headers->get('Content-Type', self::CONTENT_TYPE_JSON);

        return self::getFormatSupported($header) === self::CONTENT_TYPE_JSON;
    }

    /**
     * Indique si l'en-tête Content-Type correspond à du XML.
     *
     * @return bool
     */
    public function isContentTypeXML(): bool
    {
        $header = $this->request->headers->get('Content-Type', self::CONTENT_TYPE_JSON);

        return self::getFormatSupported($header) === self::CONTENT_TYPE_XML;
    }

    /**
     * Normalise une valeur de format vers l'une des constantes supportées.
     *
     * @param string|null $format Valeur brute (extension ou type MIME)
     *
     * @return string|null Constante de format, ou null si non supporté
     */
    public static function getFormatSupported(?string $format): ?string
    {
        $xmlAliases = [
            "application/fhir+xml",
            "application/xml+fhir",
            "application/xml",
            "xml",
        ];

        $jsonAliases = [
            "application/fhir+json",
            "application/json+fhir",
            "application/json",
            "json",
        ];

        if (in_array($format, $xmlAliases, true)) {
            return self::CONTENT_TYPE_XML;
        }

        if (in_array($format, $jsonAliases, true)) {
            return self::CONTENT_TYPE_JSON;
        }

        return null;
    }
}
