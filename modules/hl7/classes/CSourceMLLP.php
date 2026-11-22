<?php
/**
 * @package Hospilibre\Hl7
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Hl7;

use Exception;
use Ox\Core\CApp;
use Ox\Core\CAppUI;
use Ox\Core\Chronometer;
use Ox\Core\CMbException;
use Ox\Core\Contracts\Client\ClientInterface;
use Ox\Interop\Eai\Client\Legacy\CMLLPLegacy;
use Ox\Interop\Eai\Client\Legacy\MLLPClientInterface;
use Hospilibre\System\CSocketSource;

class CSourceMLLP extends CSocketSource
{
    // Source type
    public const TYPE = 'mllp';

    /** @var string  */
    protected const DEFAULT_CLIENT = self::CLIENT_MLLP;

    /** @var array  */
    protected const CLIENT_MAPPING = [
        self::CLIENT_MLLP => CMLLPLegacy::class
    ];

    /** @var string */
    public const CLIENT_MLLP = 'mllp_client';

    /**
     * Start of an MLLP message
     */
    const TRAILING = "\x0B";     // \v Vertical Tab (VT, decimal 11)

    /**
     * End of an MLLP message
     */
    const LEADING = "\x1C\x0D"; // File separator (FS, decimal 28), \r Carriage return (CR, decimal 13)

    public $source_mllp_id;

    public $ssl_enabled;

    /** @var int Délai d'expiration, en secondes, pour l'appel systÎ¸me connect() */
    public $timeout_socket;
    /** @var int Délai d'expiration lors de la lecture/écriture de données via un socket */
    public $timeout_period_stream;
    /** @var int Configure le mode bloquant d'un flux */
    public $set_blocking;

    /** @var CExchangeMLLP $_exchange_mllp MLLP exchange */
    public $_exchange_mllp;

    /** @var Chronometer */
    public $chrono;

    /**
     * @see parent::getSpec()
     */
    public function getSpec()
    {
        $spec        = parent::getSpec();
        $spec->table = 'source_mllp';
        $spec->key   = 'source_mllp_id';

        return $spec;
    }

    /**
     * @see parent::getProps()
     */
    public function getProps()
    {
        $specs                          = parent::getProps();
        $specs["port"]                  = "num default|7001";
        $specs["ssl_enabled"]           = "bool notNull default|0";
        $specs["ssl_certificate"]       = "str";
        $specs["ssl_passphrase"]        = "password show|0 loggable|0";
        $specs["iv_passphrase"]         = "str show|0 loggable|0";
        $specs["timeout_socket"]        = "num default|5";
        $specs["timeout_period_stream"] = "num";
        $specs["set_blocking"]          = "bool default|0";

        return $specs;
    }

    /**
     * @see parent::updateFormFields()
     */
    public function updateFormFields()
    {
        parent::updateFormFields();
        $this->_view = $this->port;
    }

    /**
     * @see parent::updateEncryptedFields()
     */
    public function updateEncryptedFields()
    {
        if ($this->ssl_passphrase === "") {
            $this->ssl_passphrase = null;
        } else {
            if (!empty($this->ssl_passphrase)) {
                $this->ssl_passphrase = $this->encryptString($this->ssl_passphrase, "iv_passphrase");
            }
        }
    }

    /**
     * @return MLLPClientInterface
     * @throws CMbException
     */
    public function getClient(): ClientInterface
    {
        if (!$this->_client) {
            $this->_client = parent::getClient();
        }

        return $this->_client;
    }

    /**
     * Recording the exchange before the request
     *
     * @param string $stream_type Stream function
     * @param bool   $server
     * @param string $input       Input
     *
     * @return void
     * @throws Exception
     */
    public function onBeforeRequest(string $stream_type, bool $server = false, string $input = null): void
    {
        if (!$this->loggable) {
            return;
        }

        $exchange_mllp                = new CExchangeMLLP();
        $exchange_mllp->date_echange  = "now";
        $exchange_mllp->emetteur      = $server ? "$this->host:$this->port" : CAppUI::conf("mb_id");
        $exchange_mllp->function_name = $stream_type;
        $exchange_mllp->source_class  = $this->_class;
        $exchange_mllp->source_id     = $this->_id;
        $exchange_mllp->destinataire  = $server ? CAppUI::conf("mb_id") : "$this->host:$this->port";
        $exchange_mllp->input        = serialize($input);
        $exchange_mllp->store();

        $this->_exchange_mllp = $exchange_mllp;

        CApp::$chrono->stop();

        $this->chrono = new Chronometer();
        $this->chrono->start();
    }

    /**
     * Recording the exchange after the request
     *
     * @param string|resource|false $result Result
     *
     * @return void
     * @throws Exception
     */
    public function onAfterRequest($result): void
    {
        if (!$this->loggable) {
            return;
        }

        $this->chrono->stop();
        CApp::$chrono->start();

        $exchange_mllp                = $this->_exchange_mllp;
        $exchange_mllp->date_echange  = "now";
        $exchange_mllp->response_time = $this->chrono->total;
        $exchange_mllp->output        = serialize($result);
        $exchange_mllp->store();
    }
}
