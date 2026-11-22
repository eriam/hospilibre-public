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
use Ox\Core\Contracts\Client\FTPClientInterface;

class CSourceFTP extends CSourceFile
{
    // Source type
    public const TYPE = 'ftp';

    /** @var string[]  */
    protected const CLIENT_MAPPING = [
        self::CLIENT_FTP => CFTP::class
    ];

    /** @var string */
    protected const DEFAULT_CLIENT = self::CLIENT_FTP;

    /** @var string  */
    public const CLIENT_FTP = 'ftp';

    // DB Table key
    public $source_ftp_id;

    // DB Fields
    public $port;
    public $default_socket_timeout;
    public $timeout;
    public $pasv;
    public $mode;
    public $fileprefix;
    public $fileextension;
    public $filenbroll;
    public $fileextension_write_end;
    public $counter;
    public $ssl;
    public $delete_file;
    public $ack_prefix;
    public $timestamp_file;

    /**
     * @inheritdoc
     */
    function getSpec()
    {
        $spec        = parent::getSpec();
        $spec->table = 'source_ftp';
        $spec->key   = 'source_ftp_id';

        return $spec;
    }

    /**
     * @inheritdoc
     */
    function getProps()
    {

        $props                            = parent::getProps();
        $props["ssl"]                     = "bool default|0";
        $props["port"]                    = "num default|21";
        $props["default_socket_timeout"]  = "num default|1";
        $props["timeout"]                 = "num default|5";
        $props["pasv"]                    = "bool default|0";
        $props["mode"]                    = "enum list|FTP_ASCII|FTP_BINARY default|FTP_BINARY";
        $props["counter"]                 = "str protected loggable|0";
        $props["fileprefix"]              = "str";
        $props["fileextension"]           = "str";
        $props["filenbroll"]              = "enum list|1|2|3|4";
        $props["fileextension_write_end"] = "str";
        $props["delete_file"]             = "bool default|1";
        $props["ack_prefix"]              = "str";
        $props["timestamp_file"]          = "bool default|0";

        return $props;
    }

    /**
     * @return FTPClientInterface
     * @throws CMbException
     */
    public function getClient(): ClientInterface
    {
        /** @var FTPClientInterface $client_ftp */
        $client_ftp = parent::getClient();

        // todo add circuit breaker

        return $client_ftp;
    }
}
