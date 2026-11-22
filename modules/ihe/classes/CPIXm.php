<?php
/**
 * @package Hospilibre\Ihe
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Ihe;

use Ox\Interop\Eai\CExchangeDataFormat;
use Ox\Interop\Fhir\Resources\R4\Patient\CFHIRResourcePatient;

/**
 * Class CPIXm
 * Patient Demographics Query for Mobile
 */
class CPIXm extends CIHEFHIR
{
    /** @var string */
    public const TYPE = 'PIXm';

    /**
     * @var array
     */
    public static $transaction_iti83 = [
        "ihe-pix",
    ];

    /**
     * @var array
     */
    public static $evenements = [
        // ITI-83
        "ihe-pix" => "CFHIROperationIhePix",
    ];

    /**
     * Construct
     *
     * @return CPIXm
     */
    public function __construct()
    {
        $this->domain = self::DOMAIN_ITI;
        $this->type   = "PIXm";

        $this->_categories = [
            CFHIRResourcePatient::getCanonical() => self::$transaction_iti83,
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
