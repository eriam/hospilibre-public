<?php

/**
 * @package Hospilibre\Core
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Eai\Client\Legacy;

use Exception;
use Ox\Core\CApp;
use Ox\Core\CAppUI;
use Ox\Core\Chronometer;
use Ox\Core\CHTTPClient;
use Ox\Core\CMbArray;
use Ox\Core\CMbDT;
use Ox\Core\CMbException;
use Ox\Interop\Webservices\CEchangeSOAP;
use Ox\Interop\Webservices\CMbSOAPClient;
use Ox\Interop\Webservices\CSourceSOAP;
use Hospilibre\System\CExchangeSource;
use SimpleXMLElement;
use SoapFault;

class CSOAPLegacy implements SOAPClientInterface
{
    /** @var CSourceSOAP */
    private $source;

    /** @var CMbSOAPClient */
    private $soapClient;

    /** @var array [SOAP_HEADER] */
    private $headers = [];

    /** @var array */
    private $namespaces = [];

    public function init(CExchangeSource $source): void
    {
        $this->source = $source;
    }

    /**
     * @return bool
     */
    public function isReachableSource(): bool
    {
        $check_option = [
            "local_cert" => $this->source->local_cert,
            "ca_cert"    => $this->source->cafile,
            "passphrase" => $this->source->getPassword($this->source->passphrase, "iv_passphrase"),
            "username"   => $this->source->user,
            "password"   => $this->source->getPassword(),
        ];

        if (!$this->source->safe_mode) {
            if (!CHTTPClient::checkUrl($this->source->host, $check_option, true)) {
                $this->source->_reachable = 0;
                $this->source->_message   = CAppUI::tr("CSourceSOAP-unreachable-source", $this->source->host);

                return false;
            }
        }

        return true;
    }

    /**
     * @return bool
     * @throws CMbException
     */
    public function isAuthentificate(): bool
    {
        $options = [
            "encoding"   => $this->source->encoding,
            'user_agent' => 'PhpSoapClient',
            'loggable'   => false,
            'safe_mode'  => false,
        ];

        try {
            $this->soapClient = $soap_client = $this->makeCMbSoapClient($options);
            $soap_client->checkServiceAvailability();
        } catch (Exception $e) {
            $this->source->_reachable = 1;
            $this->source->_message   = $e->getMessage();

            return false;
        }

        return true;
    }

    /**
     * @return int
     */
    public function getResponseTime(): int
    {
        return $this->source->_response_time = url_response_time($this->source->host, 80);
    }


    /**
     * @param string|null $event_name
     * @param bool   $flatten
     *
     * @return bool
     * @throws CMbException
     */
    public function send(string $event_name = null, bool $flatten = false): bool
    {
        if (!$this->source->_id) {
            throw new CMbException("CSourceSOAP-no-source", $this->source->name);
        }

        if (!$event_name) {
            $event_name = $this->source->evenement_name;
        }

        if (!$event_name) {
            throw new CMbException("CSourceSOAP-no-evenement", $this->source->name);
        }

        if ($this->source->single_parameter) {
            $this->source->_data = array("$this->source->single_parameter" => $this->source->_data);
        }

        if (!$this->source->_data) {
            $this->source->_data = array();
        }

        $options = array(
            "encoding"    => $this->source->encoding,
            "return_mode" => "normal",
            'user_agent'  => 'PhpSoapClient'
        );

        if ($this->source->return_mode) {
            $options["return_mode"] = $this->source->return_mode;
        }

        if ($this->source->soap_version) {
            $options["soap_version"] = constant($this->source->soap_version);
        }

        if ($this->source->xop_mode) {
            $options["xop_mode"] = true;
        }

        if ($this->source->use_tunnel) {
            $options["use_tunnel"] = true;
        }

        if ($this->source->feature) {
            $options["features"] = constant($this->source->feature);
        }

        $this->soapClient = $soap_client = $this->makeCMbSoapClient($options);
        if ($soap_client->hasError()) {
            throw new CMbException("CSourceSOAP-unreachable-source", $this->source->name);
        }

        // Définit un ent-Îºte Î° utiliser dans les requÎºtes ?
        if ($this->headers) {
            $soap_client->setHeaders($this->headers);
        }

        if ($this->namespaces) {
            $soap_client->setNamespaces($this->namespaces);
        }

        $arguments = $this->source->_data;
        if (!is_array($this->source->_data)) {
            $arguments = array($this->source->_data);
        }

        /* @todo Lors d'un appel d'une méthode RPC le tableau $arguments contient un élement vide array( [0] => )
         * posant problÎ¸me lors de l'appel d'une méthode du WSDL sans argument */
        if (isset($arguments[0]) && empty($arguments[0])) {
            $arguments = array();
        }

        if ($flatten && isset($arguments[0]) && !empty($arguments[0])) {
            $arguments = $arguments[0];
        }

        $exchange_soap = $this->source->beforeRequest($event_name, $arguments);

        $chrono = new Chronometer();
        $chrono->start();

        try {
            $this->source->_acquittement = $output = $soap_client->call($event_name, $arguments);
            $this->source->stopCallTrace();

            if (!$this->source->loggable) {
                CApp::$chrono->start();
            }
        }
        catch (SoapFault $fault) {
            $this->source->stopCallTrace();

            // trace
            if (CAppUI::conf("webservices trace")) {
                $soap_client->getTrace($exchange_soap);
            }

            $chrono->stop();
            $exchange_soap->response_datetime = CMbDT::dateTime();
            $exchange_soap->output            = $fault->faultstring;
            $exchange_soap->soapfault         = 1;
            $exchange_soap->response_time     = $chrono->total;
            $exchange_soap->store();

            CApp::$chrono->start();

            throw $fault;
        }

        $this->source->afterRequest($exchange_soap, $chrono, $output);

        if (!$this->source->_acquittement) {
            return true;
        }

        if (is_object($this->source->_acquittement)) {
            $acquittement = (array)$this->source->_acquittement;
            if (count($acquittement) == 1) {
                $this->source->_acquittement = reset($acquittement);
            }
        }

        return true;
    }

    /**
     * @param string $function_name
     *
     * @return bool
     * @throws CMbException
     */
    public function functionExist(string $function_name): bool
    {
        $soap_client = $this->makeCMbSoapClient();

        return $soap_client->functionExist($function_name);
    }

    /**
     * @return bool
     */
    public function hasError(): bool
    {
        if ($this->soapClient) {
            return $this->soapClient->hasError();
        }

       return false;
    }

    /**
     * @return string
     */
    public function getLastRequest(): string
    {
        if ($this->soapClient) {
            return $this->soapClient->__getLastRequest();
        }

        return "";
    }

    /**
     * @return string
     */
    public function getLastResponse(): string
    {
        if ($this->soapClient) {
            return $this->soapClient->__getLastResponse();
        }

        return "";
    }

    /**
     * @param array $headers
     *
     * @return void
     */
    public function setHeaders(array $headers): void
    {
        $this->headers = $headers;
    }

    /**
     * @param array $namespaces
     *
     * @return void
     */
    public function setNamespaces(array $namespaces): void
    {
        $this->namespaces = array_merge($this->namespaces, $namespaces);
    }

    /**
     * Instance of CMbSoapClient
     *
     * @param array $options
     *
     * @return SOAPClientInterface
     * @throws CMbException
     */
    private function makeCMbSoapClient(array $options = []): CMbSOAPClient
    {
        $login      = $this->source->user;
        $password   = $this->source->getPassword();
        $rooturl    = $this->source->host;
        $passphrase = $this->source->getPassword($this->source->passphrase, "iv_passphrase");
        $safe_mode  = $options['safe_mode'] ?? $this->source->safe_mode;

        if (($login && $password) || (array_key_exists('login', $options) && array_key_exists('password', $options))) {
            $login = $login ? $login : $options['login'];
            if (preg_match('#\%u#', $rooturl)) {
                $rooturl = str_replace('%u', $login, $rooturl);
            }
            else {
                $options['login'] = $login;
            }

            $password = $password ? $password : $options['password'];
            if (preg_match('#\%p#', $rooturl)) {
                $rooturl = str_replace('%p', $password, $rooturl);
            }
            else {
                $options['password'] = $password;
            }
        }

        $check_option["local_cert"]  = $this->source->local_cert;
        $check_option["ca_cert"]     = $this->source->cafile;
        $check_option["passphrase"]  = $passphrase;
        $check_option["username"]    = $login;
        $check_option["password"]    = $password;
        $check_option['verify_peer'] = $this->source->verify_peer;

        if (!$safe_mode) {
            if (!CHTTPClient::checkUrl($rooturl, $check_option)) {
                throw new CMbException("CSourceSOAP-unreachable-source", $rooturl);
            }
        }

        $location_for_port = null;
        if ($this->source->port_name) {
            $location_for_port = $this->getLocationForPort($this->source->host, $this->source->port_name);
        }

        $soap_client = new CMbSOAPClient(
            $rooturl,
            $this->source->type_echange,
            $options,
            $options['loggable'] ?? $this->source->loggable,
            $this->source->local_cert,
            $passphrase,
            $safe_mode,
            $this->source->verify_peer,
            $this->source->cafile,
            $this->source->wsdl_external,
            $this->source->socket_timeout,
            $this->source->connection_timeout,
            $location_for_port
        );
        $soap_client->init($this->source);

        return $soap_client;
    }


    /**
     * Get location for port
     *
     * @param string $wsdl     WSDL
     * @param string $portName Port name
     *
     * @return bool|string
     * @throws Exception
     */
    private function getLocationForPort($wsdl, $portName)
    {
        $file = file_get_contents($wsdl);

        $xml = new SimpleXmlElement($file);

        $query   = "wsdl:service/wsdl:port[@name='$portName']/soap:address";
        $address = $xml->xpath($query);
        if (!empty($address)) {
            return (string)CMbArray::get(CMbArray::get($address, 0), "location");
        }

        return false;
    }


    /**
     * @param CEchangeSOAP $exchange_source
     *
     * @return void
     */
    public function getTrace(CEchangeSOAP $exchange_source): void
    {
        if ($this->soapClient) {
            $this->soapClient->getTrace($exchange_source);
        }
    }

    /**
     * @return array
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Return the list of the operation of the WSDL
     *
     * @return array An array who contains all the operation of the WSDL
     * @throws CMbException
     */
    public function getFunctions(): array
    {
        $soap_client = $this->soapClient;
        if (!$soap_client) {
            $soap_client = $this->makeCMbSoapClient();
        }

        return $soap_client->__getFunctions();
    }

    /**
     * Returns an array of functions described in the WSDL for the Web service.
     *
     * @return array The array of SOAP function prototype
     * @throws CMbException
     */
    public function getTypes(): array
    {
        $soap_client = $this->soapClient;
        if (!$soap_client) {
            $soap_client = $this->makeCMbSoapClient();
        }

        return $soap_client->__getTypes();
    }


    /**
     * Check service availability
     *
     * @throws CMbException
     *
     * @return void
     */
    public function checkServiceAvailability(): void
    {
        $soap_client = $this->soapClient;
        if (!$soap_client) {
            $soap_client = $this->makeCMbSoapClient();
        }

        $soap_client->checkServiceAvailability();
    }
}
