<?php

namespace Ox\Interop\Eai\Client\Legacy;

use Ox\Core\CMbException;
use Ox\Interop\Hl7\CSourceMLLP;
use Hospilibre\System\CExchangeSource;

class CMLLPLegacy implements MLLPClientInterface
{
    /**
     * @var CSourceMLLP
     */
    private $source;

    public function init(CExchangeSource $mllp_source): void
    {
        $this->source = $mllp_source;
    }

    public function isReachableSource(): bool
    {
        try {
            $this->getSocketClient();
        } catch (Exception $e) {
            $this->source->_reachable = 0;
            $this->source->_message   = $e->getMessage();

            return false;
        }

        return true;
    }

    public function isAuthentificate(): bool
    {
        return $this->isReachableSource();
    }

    public function getResponseTime(): int
    {
        $response_time = url_response_time($this->source->host, $this->source->port);
        return $this->source->_response_time = intval($response_time);
    }

    public function send(): void
    {

        $data = $this->source::TRAILING . $this->source->_data . $this->source::LEADING;
        $this->source->startCallTrace();
        $socket = $this->getSocketClient();

        if ($this->source->timeout_period_stream) {
            stream_set_timeout($socket, $this->source->timeout_period_stream);
        }

        $this->source->onBeforeRequest('fwrite', false, $data);
        $return = fwrite($socket, $data, strlen($data));
        $this->source->onAfterRequest($return);
        //$this->source->stopCallTrace();

        $acq = $this->receive();

        $this->source->_acquittement = trim(str_replace("\x1C", "", $acq));
    }

    public function receive(): string
    {
        $servers = [$this->getSocketClient()];

        if ($this->source->timeout_period_stream) {
            stream_set_timeout($this->source->_socket_client, $this->source->timeout_period_stream);
        }

        $data = "";
        $this->source->startCallTrace();
        $this->source->onBeforeRequest('stream_get_contents');
        do {
            $var = $write = null;
            $except = null;
            while (@stream_select($servers, $var, $except, $this->source->timeout_socket) === false) {
                ;
            }
            $buf  = stream_get_contents($this->source->_socket_client);
            $data .= $buf;
        } while ($buf);
        $this->source->onAfterRequest($data);

        $this->source->stopCallTrace();

        return $data;
    }

    public function getSocketClient()
    {
        if ($this->source->_socket_client) {
            return $this->source->_socket_client;
        }

        $address = $this->source->host . ":" . $this->source->port;
        $this->source->startCallTrace();

        $context = stream_context_create();

        if ($this->source->ssl_enabled && $this->source->ssl_certificate && is_readable($this->source->ssl_certificate)) {
            $address = "tls://$address";

            stream_context_set_option($context, 'ssl', 'local_cert', $this->source->ssl_certificate);

            if ($this->source->ssl_passphrase) {
                $ssl_passphrase = $this->source->getPassword($this->source->ssl_passphrase, "iv_passphrase");
                stream_context_set_option($context, 'ssl', 'passphrase', $ssl_passphrase);
            }
        }

        $this->source->onBeforeRequest('stream_socket_client');
        /** @var resource|false $socket_client */
        $socket_client = $this->source->_socket_client = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            $this->source->timeout_socket,
            STREAM_CLIENT_CONNECT,
            $context
        );
        $this->source->onAfterRequest($socket_client);

        if (!$socket_client) {
            $this->source->stopCallTrace();
            throw new CMbException("CSourceMLLP-unreachable-source", $this->source->name, $errno, $errstr);
        }

        stream_set_blocking($socket_client, $this->source->set_blocking);
        $this->source->stopCallTrace();

        return $socket_client;
    }

    /**
     * @return string
     */
    public function getData(): string
    {
        return $this->receive();
    }

    /**
     * @see parent::isSecured()
     */
    public function isSecured()
    {
        return ($this->ssl_enabled && $this->ssl_certificate && is_readable($this->ssl_certificate));
    }

    /**
     * @see parent::getProtocol()
     */
    public function getProtocol()
    {
        return 'tcp';
    }
}
