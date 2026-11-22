<?php

/**
 * @package Hospilibre\System
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\System\Client;

use Exception;
use InvalidArgumentException;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use Ox\Core\CMbDT;
use Ox\Core\Contracts\Client\HTTPClientInterface;
use Ox\Core\HttpClient\ClientException;
use Hospilibre\System\CExchangeHTTPClient;
use Hospilibre\System\CExchangeSource;
use Hospilibre\System\CSourceHTTP;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class HTTPClientCurlLegacy implements HTTPClientInterface
{
    /** @var int */
    protected const DEFAULT_CONNECT_TIMEOUT = 5;

    /** @var int */
    protected const DEFAULT_TIMEOUT = 5;

    /** @var string */
    private const CUSTOM_TOKEN_HEADER_KEY = 'X-OXAPI-KEY';

    /** @var CSourceHTTP */
    private $source;

    /** @var CExchangeHTTPClient */
    private $http;

    public function __construct()
    {
        // keep for use client without source
        $this->source = new CSourceHTTP();
    }

    /**
     * Create and send an HTTP request.
     *
     * @param string $method
     * @param string $uri
     * @param array  $options
     *
     * @return ResponseInterface
     * @throws Exception|Throwable
     */
    public function request(string $method, string $uri, array $options = []): ResponseInterface
    {
        $request = new Request($method, $uri, $options['headers'] ?? []);

        return $this->send($request, $options);
    }

    /**
     * @param CSourceHTTP $source
     *
     * @return void
     */
    public function init(CExchangeSource $source): void
    {
        $this->source = $source;
    }

    /**
     * @return bool
     */
    public function isReachableSource(): bool
    {
        return url_exists($this->source->host, 'GET');
    }

    public function isAuthentificate(): bool
    {
        return false;
    }

    public function getResponseTime(): int
    {
        return url_response_time($this->source->host, '80');
    }

    /**
     * @param RequestInterface $request
     * @param array            $options
     *
     * @return ResponseInterface
     * @throws Exception
     * @throws Throwable
     */
    public function send(RequestInterface $request, array $options = []): ResponseInterface
    {
        $uri            = $this->getUri($request, $options);
        $this->http     = $http = new CExchangeHTTPClient($uri);
        $http->_source  = $this->source;
        $http->loggable = $this->source->loggable ?? false;
        $http->setOption(CURLOPT_HEADER, true);
        $http->setOption(CURLINFO_HEADER_OUT, true);

        if ($options['json'] ?? false) {
            if (empty($request->getHeader('Content-Type'))) {
                $request = $request->withHeader('Content-Type', 'application/json');
            }
        }

        // auth
        $request = $this->setAuth($request, $options);

        // Date header
        if (empty($request->getHeader('Date'))) {
            $request = $request->withHeader('Date', CMbDT::dateTimeGMT());
        }

        // disable peer verification
        if (($options['verify_peer'] ?? true) === false) {
            $http->setOption(CURLOPT_SSL_VERIFYPEER, false);
            $http->setOption(CURLOPT_SSL_VERIFYHOST, false);
        }

        // connect timeout
        $connect_timeout = $options['connect_timeout'] ?? self::DEFAULT_CONNECT_TIMEOUT;
        $http->setOption(CURLOPT_CONNECTTIMEOUT_MS, floatval($connect_timeout) * 1000);

        // timeout
        $timeout = $options['timeout'] ?? self::DEFAULT_TIMEOUT;
        $http->setOption(CURLOPT_TIMEOUT_MS, floatval($timeout) * 1000);

        $body   = $this->getBody($request, $options);
        $method = $request->getMethod();

        // Content-Length
        if (in_array(strtoupper($method), ['PUT', 'POST'])) {
            if (empty($request->getHeader("Content-Length")) && is_string($body)) {
                $request = $request->withHeader('Content-Length', strlen($body));
            }
        }

        // headers
        foreach ($request->getHeaders() as $header_name => $header_value) {
            if (is_array($header_value)) {
                $header_value = implode(', ', $header_value);
            }

            $http->header[] = "$header_name: $header_value";
        }

        // call
        $response = $this->call($method, $body);

        // set response on resource
        $this->source->_acquittement = $response->getBody()->__toString();

        return $response;
    }

    /**
     * @param string            $method
     * @param string|null|array $body
     *
     * @return Response
     * @throws ClientException
     * @throws Throwable
     */
    protected function call(string $method, $body): Response
    {
        $http = $this->http;

        // before request event

        // make request
        try {
            switch (strtoupper($method)) {
                case 'GET':
                    $full_response = $http->get();
                    break;
                case 'DELETE':
                    $full_response = $http->delete();
                    break;

                case 'POST':
                    $full_response = $http->post($body);
                    break;

                case 'PUT':
                    $full_response = $http->put($body);
                    break;

                default:
                    throw new ClientException('Undefined method ' . $method);
            }

            [$headers, $response] = explode("\r\n\r\n", $full_response, 2);

            $parsed_headers = $http->parseHeaders($headers);
            $response_psr   = new Response(
                $parsed_headers['HTTP_Code'] ?: '500',
                $parsed_headers,
                $response,
                $parsed_headers['HTTP_Version'] ?: '1.1',
                $parsed_headers["HTTP_Message"] ?: null
            );

            // on response event

            return $response_psr;
        } catch (Throwable $exception) {
            // on exception event

            throw $exception;
        } finally {
            // after request event
        }
    }

    /**
     * Set auth header if not already set
     *
     * @param RequestInterface $request
     * @param array            $options
     *
     * @return void
     */
    protected function setAuth(RequestInterface $request, array $options): RequestInterface
    {
        if (!empty($request->getHeader('Authorization'))) {
            return $request;
        }

        $http = $this->http;

        if ($ox_token = $options[self::CUSTOM_TOKEN_HEADER_KEY] ?? null) { // ox header auth
            $request = $request->withHeader(self::CUSTOM_TOKEN_HEADER_KEY, $ox_token);
        } elseif ($auth_bearer = ($options['auth_bearer'] ?? null)) { // auth Bearer with classic token
            $auth_bearer = str_replace('Bearer ', '', $auth_bearer);

            $request = $request->withHeader('Authorization', "Bearer $auth_bearer");
        } elseif ($this->source->user && $this->source->password) { // auth with user name password
            $http->setHTTPAuthentification($this->source->user, $this->source->password);
        } elseif ($auth_basic = ($options['auth_basic'] ?? null)) { // Basic
            if (is_array($auth_basic)) {
                $auth_basic = implode(":", $auth_basic);
            }

            $http->setOption(CURLOPT_USERPWD, $auth_basic);
        }

        return $request;
    }

    /**
     * @param RequestInterface $request
     * @param array            $options
     *
     * @return string|null|array
     */
    protected function getBody(RequestInterface $request, array $options = [])
    {
        if ($request->getBody() && ($body = $request->getBody()->__toString())) {
            return $body;
        }

        if (!$body = ($options['body'] ?? null)) {
            $body = ($options['json'] ?? null);
        }

        return $body;
    }

    /**
     * @param RequestInterface $request
     * @param array            $options
     *
     * @return string
     * @throws InvalidArgumentException
     */
    protected function getUri(RequestInterface $request, array $options): string
    {
        $uri        = $request->getUri();
        $position   = strpos($uri, '?');
        $parameters = ($position !== false) ? substr($uri, $position + 1) : '';
        $uri        = substr($uri, 0, $position !== false ? $position : strlen($uri));

        if ($query = ($options['query'] ?? null)) {
            if (is_array($query)) {
                $query = http_build_query($query, null, '&', PHP_QUERY_RFC3986);
            }

            if (!is_string($query)) {
                throw new InvalidArgumentException('query must be a string or array');
            }
        }

        if ($parameters = $query ? "$parameters&" . ltrim($query, '&') : $parameters) {
            return rtrim($uri, '?') . '?' . ltrim($parameters, '?');
        }

        return rtrim($uri, '?');
    }
}
