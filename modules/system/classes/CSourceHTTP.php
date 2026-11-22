<?php

/**
 * @package Hospilibre\System
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\System;

use Ox\Core\CMbException;
use Ox\Core\Contracts\Client\ClientInterface;
use Ox\Core\Contracts\Client\HTTPClientInterface;
use Hospilibre\System\Client\HTTPClientCurlLegacy;

/**
 * Source HTTP, using cURL, allowing traceability
 */
class CSourceHTTP extends CExchangeSource
{
    // Source type
    public const TYPE = 'http';

    /** @var string[] */
    protected const CLIENT_MAPPING = [
      self::CLIENT_HTTP_LEGACY_CURL => HTTPClientCurlLegacy::class
    ];

    /** @var string */
    protected const DEFAULT_CLIENT = self::CLIENT_HTTP_LEGACY_CURL;

    /** @var string */
    public const CLIENT_HTTP_LEGACY_CURL = 'curl_leg';

    /** @var int Primary key */
    public $source_http_id;
    /** @var string */
    public $token;

    /**
     * @inheritdoc
     */
    public function getSpec()
    {
        $spec        = parent::getSpec();
        $spec->table = 'source_http';
        $spec->key   = 'source_http_id';

        return $spec;
    }

    /**
     * @inheritdoc
     */
    public function getProps()
    {
        $props          = parent::getProps();
        $props["token"] = "str";

        return $props;
    }

    /**
     * @param string|null $evenement_name
     *
     * @return string|null
     */
    public function getHost(?string $evenement_name = null): ?string
    {
        if (!$host = $this->host) {
            return null;
        }

        if ($evenement_name !== null) {
            $host = rtrim($host, "/") . "/" . ltrim($evenement_name, "/");
        }

        return $host;
    }

    /**
     * @return HTTPClientInterface
     * @throws CMbException
     */
    public function getClient(): ClientInterface
    {
        /** @var HTTPClientInterface $client */
        $client = parent::getClient();

        return $client;
    }

    /**
     * @return mixed
     */
    public function getToken()
    {
        return $this->token;
    }
}
