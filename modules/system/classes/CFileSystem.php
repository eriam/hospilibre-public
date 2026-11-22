<?php

/**
 * @package Hospilibre\Core
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\System;

use Exception;
use Ox\Core\CApp;
use Ox\Core\CAppUI;
use Ox\Core\Chronometer;
use Ox\Core\CMbArray;
use Ox\Core\CMbDT;
use Ox\Core\CMbException;
use Ox\Core\CMbString;
use Ox\Core\Contracts\Client\FileSystemClientInterface;

class CFileSystem implements FileSystemClientInterface
{
    /** @var CSourceFileSystem */
    private $source;

    /**
     * @var int
     */
    private $source_id;

    /**
     * @var string
     */
    private $source_class;

    /**
     * @var string
     */
    private $input;

    /**
     * @var string
     */
    private $destinataire;

    /**
     * @var mixed|null
     */
    private $emetteur;

    /**
     * @var float|int|string
     */
    private $date_echange;

    /** @var string */
    public $_path;

    /** @var string */
    public $_file_path;

    /** @var array */
    public $_files = [];

    /** @var array */
    public $_dir_handles = [];

    public $_limit;

    /** @var bool */
    public $_acknowledgement = false;

    public $_chrono;

    /**
     * @param CSourceFileSystem $source
     *
     * @return void
     * @throws Exception
     */
    public function init(CExchangeSource $source): void
    {
        $this->date_echange = CMbDT::dateTime();
        $this->emetteur     = CAppUI::conf("mb_id");
        $this->destinataire = $source->host;
        $this->input        = serialize($source->_data);
        $this->source_class = $source->_class;
        $this->source_id    = $source->_id;
        $this->source       = $source;
        $this->_limit       = $source->_limit;
    }

    public function isReachableSource(): bool
    {
        if (is_dir($this->source->host)) {
            return true;
        } else {
            $this->source->_reachable = 0;
            $this->source->_message   = CAppUI::tr("CSourceFileSystem-path-not-found", $this->source->host);

            return false;
        }
    }

    public function isAuthentificate(): bool
    {
        if (is_writable($this->source->host)) {
            return true;
        } else {
            $this->source->_reachable = 1;
            $this->source->_message   = CAppUI::tr("CSourceFileSystem-path-not-writable", $this->source->host);

            return false;
        }
    }

    public function getResponseTime(): int
    {
        $start = microtime(true);
        $this->isReachableSource();
        $end           = microtime(true);
        $response_time = $end - $start;

        return $this->source->response_time = intval($response_time);
    }

    public function send(string $destination_basename = null): bool
    {
        $exchange_fs = $this->startExchange("file_put_contents");

        $file_path = !$destination_basename ? $this->source->generateFileName() : $destination_basename;

        // Ajout du prefix si existant
        $file_path = $this->source->fileprefix . $file_path;

        if ($this->source->_exchange_data_format && $this->source->_exchange_data_format->_id) {
            $file_path = "$file_path-{$this->source->_exchange_data_format->_id}";
        }
        $this->_file_path = $file_path;

        if ($this->source->fileextension && (CMbArray::get(
                    pathinfo($file_path),
                    "extension"
                ) != $this->source->fileextension)) {
            $this->_file_path .= ".{$this->source->fileextension}";
        }

        $path      = rtrim($this->source->getFullPath($this->_path), "\\/");
        $file_path = $path . "/" . $this->_file_path;

        if (!is_writable($path)) {
            $this->stopExchange($exchange_fs, CAppUI::tr("CSourceFileSystem-path-not-writable", $path));
            throw new CMbException("CSourceFileSystem-path-not-writable", $path);
        }

        if ($this->source->fileextension_write_end) {
            file_put_contents($file_path, $this->source->_data);

            $pos       = strrpos($file_path, ".");
            $file_path = substr($file_path, 0, $pos);

            $return = file_put_contents("$file_path.{$this->source->fileextension_write_end}", "");
        } else {
            $return = file_put_contents($file_path, $this->source->_data);
        }

        return $this->stopExchange($exchange_fs, $return);
    }

    public function receive(): array
    {
        $exchange_fs = $this->startExchange("readdir");

        $path = $this->source->getFullPath($this->_path);


        if (!is_dir($path)) {
            $this->stopExchange($exchange_fs, CAppUI::tr("CSourceFileSystem-path-not-found", $path));
            throw new CMbException("CSourceFileSystem-path-not-found", $path);
        }

        if (!is_readable($path)) {
            $this->stopExchange($exchange_fs, CAppUI::tr("CSourceFileSystem-path-not-readable", $path));
            throw new CMbException("CSourceFileSystem-path-not-readable", $path);
        }

        if (!$handle = opendir($path)) {
            $this->stopExchange($exchange_fs, CAppUI::tr("CSourceFileSystem-path-not-readable", $path));
            throw new CMbException("CSourceFileSystem-path-not-readable", $path);
        }

        /* Loop over the directory
         * $this->_files = CMbPath::getFiles($path); => pas optimisé pour un listing volumineux
         * */
        $i     = 1;
        $files = [];

        while (false !== ($entry = readdir($handle))) {
            $limit = 5000;
            $entry = "$path/$entry";
            if ($i == $limit) {
                break;
            }

            /* We ignore folders */
            if (is_dir($entry)) {
                continue;
            }

            $files[] = $entry;

            $i++;
        }

        closedir($handle);

        switch ($this->source->sort_files_by) {
            default:
            case "name":
                sort($files);
                break;
            case "date":
                usort($files, [$this, "sortByDate"]);
                break;
            case "size":
                usort($files, [$this, "sortBySize"]);
                break;
        }

        if ($this->_limit) {
            $files = array_slice($files, 0, $this->_limit);
        }

        return $this->_files = $this->stopExchange($exchange_fs, $files);
    }

    public function getSize(string $file_name): int
    {
        if ($this->_path) {
            $path      = rtrim($this->source->getFullPath($this->_path), "\\/");
            $file_name = "$path/$file_name";
        }

        $size = filesize($file_name);

        return $size;
    }

    public function renameFile(
        string $oldname,
        string $newname,
        string $current_directory = null,
        bool $utf8_encode = false
    ): void {
        $exchange_fs       = new CExchangeFileSystem();
        $current_directory = $this->source->host . '/';
        $path              = $current_directory . $oldname;

        if (rename($path, $current_directory . $newname) === false) {
            $this->stopExchange($exchange_fs, CAppUI::tr("CSourceFileSystem-error-renaming", $oldname));
            throw new CMbException("CSourceFileSystem-error-renaming", $oldname);
        }
        //return $this->source->updateExchange($exchange_fs, true);
    }

    public function createDirectory(string $directory_name): bool
    {
        $path = $this->source->getFullPath($this->_path) . "/" . $directory_name;
        if (!is_dir($path) && mkdir($path) === false) {
            throw new CMbException("CSourceFileSystem-error-createDirectory", $directory_name);

            return false;
        }

        return true;
    }

    public function changeDirectory(string $directory): void
    {
    }

    public function getCurrentDirectory(string $directory = null): string
    {
        if (!$directory) {
            $directory = $this->source->host;
            if (substr($directory, -1, 1) !== "/" && substr($directory, -1, 1) !== "\\") {
                $directory = "$directory/";
            }
        }

        return str_replace("\\", "/", $directory);
    }

    public function getListFiles(string $current_directory, bool $information = false): array
    {
        $directory = $this->source->getFullPath($this->_path) . "/" . $current_directory;
        if (!file_exists($directory)) {
            CAppUI::stepAjax("CSourceFileSystem-msg-Folder does not exist", UI_MSG_ERROR);
        }

        $files = [];
        foreach (scandir($directory) as $_file) {
            if ($_file == "." || $_file == "..") {
                continue;
            }

            $files[] = $_file;
        }

        return $files;
    }

    public function getListFilesDetails(string $current_directory): array
    {
        if (!file_exists($current_directory)) {
            CAppUI::stepAjax("CSourceFileSystem-msg-Folder does not exist", UI_MSG_ERROR);
        }
        $contain  = scandir($current_directory);
        $fileInfo = [];
        foreach ($contain as $_contain) {
            $full_path = $current_directory . $_contain;
            if (!is_dir($full_path) && @filetype($full_path) && !is_link($full_path)) {
                $fileInfo[] = [
                    "type"         => "f",
                    "user"         => fileowner($full_path),
                    "size"         => CMbString::toDecaBinary($this->getSize($full_path, true)),
                    "date"         => CMbDT::strftime(CMbDT::ISO_DATETIME, filemtime($full_path)),
                    "name"         => $_contain,
                    "relativeDate" => CMbDT::daysRelative(fileatime($full_path), CMbDT::date()),
                ];
            }
        }

        return $fileInfo;
    }

    public function getListDirectory(string $current_directory): array
    {
        $contain = scandir($current_directory);
        $dir     = [];
        foreach ($contain as $_contain) {
            $full_path = $current_directory . $_contain;
            if (is_dir($full_path) && "$_contain/" !== "./" && "$_contain/" !== "../") {
                $dir[] = "$_contain/";
            }
        }

        return $dir;
    }

    public function addFile(string $source_file, string $file_name): bool
    {
        $exchange_fs = new CExchangeFileSystem();
        if (copy($source_file, $this->source->host . "/" . $file_name) === false) {
            $this->stopExchange($exchange_fs, CAppUI::tr("CSourceFileSystem-error-add", $file_name));
            throw new CMbException("CSourceFileSystem-error-add", $file_name);
        }

        return $this->stopExchange($exchange_fs, true);
    }

    public function delFile(string $path): bool
    {
        $current_directory = $this->source->_destination_file;
        $exchange_fs       = $this->startExchange("unlink");
        if ($current_directory) {
            $path = rtrim($current_directory, '/') . '/' . $path;
        }

        if (file_exists($path) && unlink($path) === false) {
            $this->stopExchange($exchange_fs, CAppUI::tr("CSourceFileSystem-file-not-deleted", $path));
            throw new CMbException("CSourceFileSystem-file-not-deleted", $path);
        }

        return $this->stopExchange($exchange_fs, true);
    }

    public function getError(): ?string
    {
        return null;
    }

    /**
     * @param string      $path
     * @param string|null $dest
     *
     * @return string|null
     * @throws CMbException
     */
    public function getData(string $path, ?string $dest = null): ?string
    {
        $exchange_fs = $this->startExchange("file_get_contents");
        if (!is_readable($path)) {
            $path = $this->source->host . "/" . $path;
        }

        return $this->stopExchange($exchange_fs, file_get_contents($path));
    }

    /**
     * Init source file system
     *
     * @param string $function_name Function name
     *
     * @return CExchangeFileSystem
     * @throws CMbException
     *
     */
    function startExchange($function_name = null)
    {
        $exchange_fs = new CExchangeFileSystem();

        if (!$this->source_id) {
            throw new CMbException("CSourceFileSystem-no-source", $this->source->name);
        }

        if (!is_dir($this->source->host ?? '')) {
            throw new CMbException("CSourceFileSystem-host-not-a-dir", $this->source->host);
        }

        if (!$this->source->loggable || !$function_name) {
            return $exchange_fs;
        }

        $exchange_fs->date_echange  = CMbDT::dateTime();
        $exchange_fs->emetteur      = CAppUI::conf("mb_id");
        $exchange_fs->destinataire  = $this->source->host;
        $exchange_fs->function_name = $function_name;
        $exchange_fs->input         = serialize($this->source->_data);
        $exchange_fs->source_class  = $this->source->_class;
        $exchange_fs->source_id     = $this->source->_id;

        $exchange_fs->store();

        CApp::$chrono->stop();
        $this->chrono = new Chronometer();
        $this->chrono->start();

        return $exchange_fs;
    }

    /**
     * Update exchange
     *
     * @param CExchangeFileSystem $exchange_fs Exchange
     * @param string              $output      Output
     *
     * @return mixed
     */
    function stopExchange(CExchangeFileSystem $exchange_fs, $output = null)
    {
        if (!$this->source->loggable) {
            return $output;
        }

        $exchange_fs->date_echange = CMbDT::dateTime();
        if ($output) {
            $exchange_fs->output = serialize($output);
        }
        $exchange_fs->store();

        $this->chrono->stop();
        CApp::$chrono->start();

        // response time
        $exchange_fs->response_time     = $this->chrono->total;
        $exchange_fs->response_datetime = CMbDT::dateTime();
        $exchange_fs->store();

        return $output;
    }

    /**
     * Sort by date
     *
     * @param int $a Variable a
     * @param int $b Variable b
     *
     * @return int
     */
    private function sortByDate(int $a, int $b): int
    {
        return filemtime($a) - filemtime($b);
    }

    /**
     * Sort by size
     *
     * @param int $a Variable a
     * @param int $b Variable b
     *
     * @return int
     */
    private function sortBySize(int $a, int $b): int
    {
        return filesize($a) - filesize($b);
    }
}
