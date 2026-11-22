<?php

/**
 * @package Hospilibre\Ftp
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Ftp;

use Ox\Core\CMbException;
use Ox\Core\Contracts\Client\ClientInterface;
use Hospilibre\System\Sources\CSourceFile;
use Ox\Core\Contracts\Client\SFTPClientInterface;

/**
 * Source SFTP
 */
class CSourceSFTP extends CSourceFile
{
    // Source type
    public const TYPE = 'sftp';

    /** @var string */
    protected const DEFAULT_CLIENT = self::CLIENT_SFTP;

    /** @var array */
    protected const CLIENT_MAPPING = [
        self::CLIENT_SFTP => CSFTP::class
    ];

    /** @var string */
    public const CLIENT_SFTP = 'sftp';

    /** @var integer Primary key */
    public $source_sftp_id;
    public $port;
    public $timeout;
    public $fileprefix;
    public $fileextension_write_end;
    public $fileextension;
    public $delete_file;

    /**
     * @inheritdoc
     */
    public function getSpec()
    {
        $spec        = parent::getSpec();
        $spec->table = "source_sftp";
        $spec->key   = "source_sftp_id";

        return $spec;
    }

    /**
     * @inheritdoc
     */
    public function getProps()
    {
        $props = parent::getProps();

        $props["port"]                    = "num default|22";
        $props["timeout"]                 = "num default|10";
        $props["fileprefix"]              = "str";
        $props["fileextension_write_end"] = "str";
        $props["fileextension"]           = "str";
        $props["delete_file"]             = "bool default|1";

        return $props;
    }

    /**
     * @return SFTPClientInterface
     * @throws CMbException
     */
    public function getClient(): ClientInterface
    {
        /** @var SFTPClientInterface $client_sftp */
        $client_sftp = parent::getClient();

        // todo circuit breaker

        return $client_sftp;
    }
}
