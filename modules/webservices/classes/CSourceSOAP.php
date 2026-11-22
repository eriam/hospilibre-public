<?php
/**
 * @package Hospilibre\Webservices
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Webservices;

use Exception;
use Ox\Core\CApp;
use Ox\Core\CAppUI;
use Ox\Core\Chronometer;
use Ox\Core\CMbDT;
use Ox\Core\CMbObjectSpec;
use Ox\Core\CMbString;
use Ox\Core\Contracts\Client\ClientInterface;
use Ox\Interop\Eai\Client\Legacy\SOAPClientInterface;
use Hospilibre\System\CExchangeSource;
use Ox\Interop\Eai\Client\Legacy\CSOAPLegacy;
use SoapHeader;

/**
 * Class CSourceSOAP
 * Source SOAP
 */
class CSourceSOAP extends CExchangeSource
{
    // Source type
    public const TYPE = 'soap';

    /** @var string */
    public const CLIENT_SOAP = 'soap_client';

    /** @var string */
    protected const DEFAULT_CLIENT = self::CLIENT_SOAP;

    /** @var string[] */
    protected const CLIENT_MAPPING = [
      self::CLIENT_SOAP => CSOAPLegacy::class
    ];

    // DB Table key
    public $source_soap_id;

    // DB Fields
    public $wsdl_external;
    public $evenement_name;
    public $single_parameter;
    public $encoding;
    public $stream_context;
    public $type_soap;
    public $local_cert;
    public $passphrase;
    public $iv_passphrase;
    public $safe_mode;
    public $return_mode;
    public $soap_version;
    public $xop_mode;
    public $use_tunnel;
    public $socket_timeout;
    public $connection_timeout;
    public $feature;
    public $port_name;
    public $client_name;

    // Options de contexte SSL
    public $verify_peer;
    public $cafile;

    /** @var SOAPClientInterface */
    protected $_soap_client;


    /** @var string */
    public $_wsdl_url;

    /**
     * Initialize object specification
     *
     * @return CMbObjectSpec the spec
     */
    function getSpec()
    {
        $spec        = parent::getSpec();
        $spec->table = 'source_soap';
        $spec->key   = 'source_soap_id';

        return $spec;
    }

    /**
     * Get properties specifications as strings
     *
     * @return array
     * @see parent::getProps()
     *
     */
    function getProps()
    {
        $specs = parent::getProps();

        $specs["wsdl_external"]      = "str";
        $specs["evenement_name"]     = "str";
        $specs["single_parameter"]   = "str";
        $specs["encoding"]           = "enum list|UTF-8|utf-8|utf-85 default|UTF-8";
        $specs["type_soap"]          = "enum list|CMbSOAPClient default|CMbSOAPClient notNull";
        $specs["iv_passphrase"]      = "str show|0 loggable|0";
        $specs["safe_mode"]          = "bool default|0";
        $specs["return_mode"]        = "enum list|normal|raw|file";
        $specs["soap_version"]       = "enum list|SOAP_1_1|SOAP_1_2 default|SOAP_1_1 notNull";
        $specs["xop_mode"]           = "bool default|0";
        $specs["use_tunnel"]         = "bool default|0";
        $specs["socket_timeout"]     = "num min|1";
        $specs["connection_timeout"] = "num min|1";
        $specs["feature"]            = "enum list|SOAP_SINGLE_ELEMENT_ARRAYS|SOAP_USE_XSI_ARRAY_TYPE|SOAP_WAIT_ONE_WAY_CALLS";
        $specs["port_name"]          = "str";

        $specs["local_cert"] = "str";
        $specs["passphrase"] = "password show|0 loggable|0";

        $specs["verify_peer"] = "bool default|0";
        $specs["cafile"]      = "str";

        $specs["stream_context"] = "str";

        return $specs;
    }

    /**
     * Calls a SOAP function
     *
     * @param string $function  Function name
     * @param array  $arguments Arguments
     *
     * @return void
     */
    function __call($function, array $arguments = array())
    {
        $this->setData(reset($arguments));
        $this->getClient()->send($function);
    }

    /**
     * Encrypt fields
     *
     * @return void
     */
    function updateEncryptedFields()
    {
        if ($this->passphrase === "") {
            $this->passphrase = null;
        } else {
            if (!empty($this->passphrase)) {
                $this->passphrase = $this->encryptString($this->passphrase, "iv_passphrase");
            }
        }
    }

    /**
     * Set SOAP header
     *
     * @param string $namespace      The namespace of the SOAP header element.
     * @param string $name           The name of the SoapHeader object
     * @param array  $data           A SOAP header's content. It can be a PHP value or a SoapVar object
     * @param bool   $mustUnderstand Value must understand
     * @param null   $actor          Value of the actor attribute of the SOAP header element
     *
     * @return void
     */
    function setHeaders($namespace, $name, $data, $mustUnderstand = false, $actor = null)
    {
        $soapClient = $this->getClient();
        $headers = $soapClient->getHeaders();
        if ($actor) {
            $headers[] = new SoapHeader($namespace, $name, $data, $mustUnderstand, $actor);
        } else {
            $headers[] = new SoapHeader($namespace, $name, $data);
        }

        $soapClient->setHeaders($headers);
    }

    /**
     * @return SOAPClientInterface
     */
    public function getClient(): ClientInterface
    {
        if (!$this->_client) {
            $this->_client = parent::getClient();
        }

        return $this->_client;
    }

    /**
     * @param string $function_name
     * @param array  $arguments
     *
     * @return CEchangeSOAP
     * @throws Exception
     */
    public function beforeRequest(string $function_name, array $arguments): CEchangeSOAP
    {
        $echange_soap = new CEchangeSOAP();

        $wsdl_url = $this->_wsdl_url ?: $this->host;
        $echange_soap->date_echange = CMbDT::dateTime();
        $echange_soap->emetteur     = CAppUI::conf("mb_id");
        $echange_soap->destinataire = $wsdl_url;
        $echange_soap->type         = $this->type_echange;
        $echange_soap->source_id    = $this->_id;
        $echange_soap->source_class = $this->_class;

        $url                            = parse_url($wsdl_url);
        $path                           = explode("/", $url['path']);
        $echange_soap->web_service_name = end($path);

        $echange_soap->function_name = $function_name;

        // Truncate input and output before storing
        $arguments_serialize = array_map_recursive(array(CSourceSOAP::class, "truncate"), $arguments);

        $echange_soap->input = serialize($arguments_serialize);

        if ($this->loggable) {
            $echange_soap->store();
        }

        CApp::$chrono->stop();

        $this->startCallTrace();

        return $echange_soap;
    }

    /**
     * @param CEchangeSOAP $exchange_soap
     * @param Chronometer  $chrono
     * @param              $output
     *
     * @return void
     * @throws Exception
     */
    public function afterRequest(CEchangeSOAP $exchange_soap, Chronometer $chrono, $output)
    {
        $chrono->stop();
        CApp::$chrono->start();

        // trace
        if (CAppUI::conf("webservices trace")) {
            $client = $this->getClient();
            $client->getTrace($exchange_soap);
        }

        // response time
        $exchange_soap->response_time     = $chrono->total;
        $exchange_soap->response_datetime = CMbDT::dateTime();

        if ($exchange_soap->soapfault != 1) {
            $exchange_soap->output = serialize(array_map_recursive(array(CSourceSOAP::class, "truncate"), $output));
        }
        $exchange_soap->store();
    }

    /**
     * Truncate a string to a given maximum length
     *
     * @param string $string The string to truncate
     *
     * @return string The truncated string
     */
    public static function truncate($string) {
        if (!is_string($string)) {
            return $string;
        }

        // Truncate
        $max    = 1024;
        $result = CMbString::truncate($string, $max);

        // Indicate true size
        $length = strlen($string);
        if ($length > 1024) {
            $result .= " [$length bytes]";
        }

        return $result;
    }
}
