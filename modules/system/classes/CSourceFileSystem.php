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
use Ox\Core\Contracts\Client\FileSystemClientInterface;
use Hospilibre\System\Sources\CSourceFile;

/**
 * Class CSourceFileSystem
 */
class CSourceFileSystem extends CSourceFile
{
    // Source type
    public const TYPE = 'file_system';

    /** @var string */
    protected const DEFAULT_CLIENT = self::CLIENT_FILE_SYSTEM;

    /** @var array */
    protected const CLIENT_MAPPING = [
        self::CLIENT_FILE_SYSTEM => CFileSystem::class
    ];

    /** @var string */
    public const CLIENT_FILE_SYSTEM = 'file_system';

    // DB Table key
    public $source_file_system_id;
    public $fileextension;
    public $fileextension_write_end;
    public $fileprefix;
    public $sort_files_by;
    public $delete_file;
    public $ack_prefix;

    // Form fields

    /** @var int Legacy field */
    public $_limit;

    /**
     * @inheritdoc
     */
    public function getSpec()
    {
        $spec        = parent::getSpec();
        $spec->table = "source_file_system";
        $spec->key   = "source_file_system_id";

        return $spec;
    }

    /**
     * @inheritdoc
     */
    public function getProps()
    {
        $props                            = parent::getProps();
        $props["fileextension"]           = "str";
        $props["fileextension_write_end"] = "str";
        $props["fileprefix"]              = "str";
        $props["sort_files_by"]           = "enum list|date|name|size default|name";
        $props["delete_file"]             = "bool default|1";
        $props["ack_prefix"]              = "str";

        return $props;
    }

    /**
     * @return FileSystemClientInterface
     * @throws CMbException
     */
    public function getClient(): ClientInterface
    {
        /** @var FileSystemClientInterface $client_fs */
        $client_fs = parent::getClient();

        // todo add circuit breaker

        return $client_fs;
    }

    /**
     * @inheritdoc
     */
    function updateFormFields()
    {
        parent::updateFormFields();

        $this->_view = $this->host;
    }

    /**
     * @param string|null $path
     *
     * @return string|null
     */
    public function getFullPath(?string $path = null): ?string
    {
        $host = rtrim($this->host, "/\\");
        $path = ltrim($path ?? '', "/\\");
        $path = $host . ($path ? "/$path" : "");

        return str_replace("\\", "/", $path);
    }
}
