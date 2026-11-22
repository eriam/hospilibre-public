<?php
/**
 * @package Hospilibre\Ihe
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Ihe;

use Ox\Interop\Eai\CExchangeDataFormat;
use Ox\Interop\Fhir\Resources\R4\Binary\CFHIRResourceBinary;
use Ox\Interop\Fhir\Resources\R4\DocumentManifest\CFHIRResourceDocumentManifest;
use Ox\Interop\Fhir\Resources\R4\DocumentReference\CFHIRResourceDocumentReference;

/**
 * Class CMHD
 * Patient Demographics Query for Mobile
 */
class CMHD extends CIHEFHIR
{
    /** @var string */
    public const TYPE = 'MHD';

    /**
     * @var array
     */
    public static $transaction_iti65 = [
        "read",
    ];

    /**
     * @var array
     */
    public static $transaction_iti67 = [
        "read",
        "search-type",
    ];

    /**
     * @var array
     */
    public static $transaction_iti68 = [
        "create",
        "read",
        "search-type",
    ];

    /**
     * @var array
     */
    public static $evenements = [
        // ITI-68
        "read"        => "CFHIRInteractionRead",
        "search-type" => "CFHIRInteractionSearch",
        "create"      => "CFHIRInteractionCreate",
    ];

    /**
     * Construct
     */
    public function __construct()
    {
        $this->domain = self::DOMAIN_ITI;
        $this->type   = self::TYPE;

        $this->_categories = [
            CFHIRResourceBinary::getCanonical()            => self::$transaction_iti65,
            CFHIRResourceDocumentManifest::getCanonical()  => self::$transaction_iti67,
            CFHIRResourceDocumentReference::getCanonical() => self::$transaction_iti68,
        ];

        parent::__construct();
    }

    /**
     * @see parent::getEvenements
     */
    public function getEvenements(): ?array
    {
        return self::$evenements;
    }

    /**
     * @see parent::getVersions
     */
    public function getVersions(): ?array
    {
        return self::$versions;
    }

    /**
     * Return data format object
     *
     * @param CExchangeDataFormat $exchange Instance of exchange
     *
     * @return object An instance of data format
     */
    public static function getEvent(CExchangeDataFormat $exchange)
    {
    }
}
