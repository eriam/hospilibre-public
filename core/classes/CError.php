<?php
/**
 * @package Hospilibre\Core
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Core;

use ErrorException;
use Exception;
use Hospilibre\System\CErrorLog;
use Hospilibre\System\CErrorLogWhiteList;
use Hospilibre\System\Cron\CCronJobLog;
use Hospilibre\System\Elastic\ErrorLogService;
use Symfony\Component\ErrorHandler\ErrorRenderer\HtmlErrorRenderer;
use Symfony\Component\HttpFoundation\Request;

/**
 * Error manager
 */
class CError
{
    /** @var int */
    const LOG_SIZE_LIMIT = 5242880; // 1024*1024*5

    /** @var string */
    const PATH_TMP_BUFFER = "/tmp/errors/";

    /** @var array */
    static $output = [];

    /** @var array */
    static $_excluded = [
        E_STRICT,
        E_DEPRECATED,        // BCB
        E_RECOVERABLE_ERROR, // Thrown by bad type hinting, to be removed
    ];

    /** @var array This errors will be thrown after converted in ErrorException */
    static $_error_throw = [
        E_ERROR,
    ];

    /** @var Request */
    private static $request;

    /**
     * @var array
     */
    public static $buffered_signatures = [];


    /**
     * @var array
     */
    static $_types = [
        "exception"         => "exception",
        E_ERROR             => "error",
        E_WARNING           => "warning",
        E_PARSE             => "parse",
        E_NOTICE            => "notice",
        E_CORE_ERROR        => "core_error",
        E_CORE_WARNING      => "core_warning",
        E_COMPILE_ERROR     => "compile_error",
        E_COMPILE_WARNING   => "compile_warning",
        E_USER_ERROR        => "user_error",
        E_USER_WARNING      => "user_warning",
        E_USER_NOTICE       => "user_notice",
        E_STRICT            => "strict",
        E_RECOVERABLE_ERROR => "recoverable_error",
        E_DEPRECATED        => "deprecated",
        E_USER_DEPRECATED   => "user_deprecated",
    ];

    static $_classes = [
        "exception"         => "big-warning",
        E_ERROR             => "big-error",   // 1
        E_WARNING           => "big-warning", // 2
        E_PARSE             => "big-info",    // 4
        E_NOTICE            => "big-info",    // 8
        E_CORE_ERROR        => "big-error",   // 16
        E_CORE_WARNING      => "big-warning", // 32
        E_COMPILE_ERROR     => "big-error",   // 64
        E_COMPILE_WARNING   => "big-warning", // 128
        E_USER_ERROR        => "big-error",   // 256
        E_USER_WARNING      => "big-warning", // 512
        E_USER_NOTICE       => "big-info",    // 1024
        E_STRICT            => "big-info",    // 2048
        E_RECOVERABLE_ERROR => "big-error",   // 4096
        E_DEPRECATED        => "big-info",    // 8192
        E_USER_DEPRECATED   => "big-info",    // 16384
        // E_ALL = 32767 (PHP 5.4)
    ];

    static $_categories = [
        "exception"         => "warning",
        E_ERROR             => "error",
        E_WARNING           => "warning",
        E_PARSE             => "error",
        E_NOTICE            => "notice",
        E_CORE_ERROR        => "error",
        E_CORE_WARNING      => "warning",
        E_COMPILE_ERROR     => "error",
        E_COMPILE_WARNING   => "warning",
        E_USER_ERROR        => "error",
        E_USER_WARNING      => "warning",
        E_USER_NOTICE       => "notice",
        E_STRICT            => "notice",
        E_RECOVERABLE_ERROR => "error",
        E_DEPRECATED        => "notice",
        E_USER_DEPRECATED   => "notice",
    ];

    /**
     * @var CLogger $logger
     */
    private static $logger;

    /**
     * @var null
     */
    private static $current_file_buffer;

    /** @var array */
    private static $errors_buffer = [];

    /** @var bool */
    private static $elastic_log;

    public static function isElasticLog(): bool
    {
        if (self::$elastic_log === null) {
            self::$elastic_log = (bool)CAppUI::conf('error_log_using_nosql');
        }

        return self::$elastic_log;
    }

    /**
     * Error handlers and configuration
     */
    public static function init($request = null)
    {
        global $dPconfig;
        // Do not set to E_STRICT as it hides fatal errors to our error handler

        // Developement
        //error_reporting(E_ALL | E_STRICT | E_USER_DEPRECATED | E_DEPRECATED);

        // Production
        error_reporting(E_ALL);

        // Legacy mode !== SF mode
        static::$request = $request;

        ini_set("log_errors_max_len", "4M");
        ini_set("log_errors", true);
        ini_set("display_errors", $dPconfig["debug"]);

        // Set Handler
        set_error_handler([static::class, 'errorHandler']);
        if (static::$request === null) {
            set_exception_handler([static::class, 'exceptionHandler']);
        } else {
            // Change the fatal error template to avoid displaying HTML errors.
            // @see \Symfony\Component\ErrorHandler\ErrorHandler::renderException
            HtmlErrorRenderer::setTemplate('templates/error.html.php');
        }

        // register shutdown
        CApp::registerShutdown([static::class, "logLastError"], CApp::ERROR_PRIORITY);
        CApp::registerShutdown([static::class, "storeCurrentFileBuffer"], CApp::ERROR_PRIORITY);

        if (static::$request === null) {
            CApp::registerShutdown([static::class, "displayErrors"], CApp::ERROR_PRIORITY);
        }
    }


    /**
     * Get error types by level : error, warning and notice
     *
     * @return array
     */
    static function getErrorTypesByCategory()
    {
        $categories = [
            "error"   => [],
            "warning" => [],
            "notice"  => [],
        ];

        foreach (self::$_categories as $_type => $_category) {
            $categories[$_category][] = self::$_types[$_type];
        }

        return $categories;
    }

    public static function getErrorType(int $error_code): string
    {
        return static::$_types[$error_code] ?? 'unknown';
    }

    /**
     * Create a link to open the file in an IDE
     *
     * @param string $file File to open in the IDE
     * @param int    $line Line number
     *
     * @return string
     */
    static function openInIDE($file, $line = null)
    {
        global $dPconfig;

        $url = null;

        $ide_url = (!empty($dPconfig["dPdeveloppement"]["ide_url"]) ? $dPconfig["dPdeveloppement"]["ide_url"] : false);
        if ($ide_url) {
            $url = str_replace("%file%", urlencode($file), $ide_url) . ":$line";
        } else {
            $ide_path = (!empty($dPconfig["dPdeveloppement"]["ide_path"]) ? $dPconfig["dPdeveloppement"]["ide_path"] : false);
            if ($ide_path) {
                $url = "ide:" . urlencode($file) . ":$line";
            }
        }

        if ($url) {
            $file = $line ? $file . ':' . $line : $file;
            $file = str_replace(CAppUI::conf("root_dir"), "", $file);
            $file = $file[0] == DIRECTORY_SEPARATOR ? substr($file, 1) : $file;

            return "<a target=\"ide-launch-iframe\" title=\"Open script in IDE\" href=\"$url\">$file</a>";
        }

        return $file;
    }

    /**
     * @return CLogger $logger
     * @throws Exception
     */
    static function getLogger()
    {
        if (is_null(static::$logger)) {
            $root_dir = CAppUI::conf('root_dir');
            $dir      = $root_dir . static::PATH_TMP_BUFFER;

            CMbPath::forceDir($dir);

            $file = $dir . CApp::getRequestUID() . ".log";

            $logger = new CLogger(CLogger::CHANNEL_ERROR);
            $logger->setJsonFormatter();
            $logger->setStreamFile($file);

            static::$current_file_buffer = $file;
            static::$logger              = $logger;
        }

        return static::$logger;
    }

    /**
     * @return array|false
     */
    static function globWaitingBuffer()
    {
        $root_dir = CAppUI::conf('root_dir');

        return glob($root_dir . static::PATH_TMP_BUFFER . "*.log", defined('GLOB_BRACE') ? GLOB_BRACE : 0);
    }

    static function clearErrorBuffer()
    {
        foreach (self::globWaitingBuffer() as $buffer) {
            unlink($buffer);
        }
        self::$current_file_buffer = null;
    }

    static function countWaitingBuffer()
    {
        return count(static::globWaitingBuffer());
    }

    /**
     * @return array|false
     * @deprecated
     */
    static function getFirstFileInWaitingBuffer()
    {
        $root_dir  = CAppUI::conf('root_dir');
        $dir       = $root_dir . static::PATH_TMP_BUFFER;
        $file      = is_dir($dir) ? CMbPath::getFirstFile($dir) : false;
        $file_path = $dir . $file;

        return is_file($file_path) ? $file_path : false;
    }

    /**
     * Because fata error (memory exhausted, max execution time ..) triggers script termination :
     * Shutdown function log uncatched error
     *
     * @throws Exception
     */
    static function logLastError()
    {
        $error = error_get_last();
        if (!is_null($error) && class_exists(CApp::class, false)) {
            $type = self::$_types[$error['type']];
            CApp::log("Uncatched {$type}", $error, CLogger::LEVEL_CRITICAL);
        }
    }


    /**
     * Store error buffer in db
     *
     * @param null $file_buffer
     * @param bool $close_ressource
     *
     * @return bool
     * @throws Exception
     *
     */
    static function storeBuffer($file_buffer = null, $close_ressource = true)
    {
        // Check file
        if (is_null($file_buffer) || !is_file($file_buffer)) {
            return false;
        }

        // Close ressource
        if ($close_ressource && self::$logger) {
            self::$logger->getLogger()->close();
        }

        // Readonly
        if (CApp::isReadonly()) {
            return false;
        }

        // Load whitelist signatures
        $whiteList = new CErrorLogWhiteList();
        if ($whiteList->isInstalled()) {
            $whitelist_hash = $whiteList->loadColumn('hash');
        } else {
            $whitelist_hash = [];
        }

        // First loop : prepare data to store
        $lines         = @file($file_buffer);
        $lines         = is_array($lines) ? $lines : [];
        $data_to_store = [];
        foreach ($lines as $_num => $_line) {
            $_line          = json_decode($_line, true);
            $_context       = CLogger::decodeContext($_line['context']);
            $signature_hash = $_context['signature_hash'];

            // When unbuffered on shutdown => final count is known
            $_final_count = static::$buffered_signatures[$signature_hash] ?? null;

            // First signature find in buffer
            if (!array_key_exists($signature_hash, $data_to_store)) {
                $data_to_store[$signature_hash] = [
                    'user_id'         => $_context['user_id'],
                    'server_ip'       => $_context['server_ip'],
                    'time'            => $_context['time'],
                    'request_uid'     => $_context['request_uid'],
                    'type'            => $_context['type'],
                    'text'            => $_context['text'],
                    'file'            => $_context['file'],
                    'line'            => $_context['line'],
                    'data'            => $_context['data'],
                    'count_in_buffer' => 1,
                    'count'           => $_final_count ?? 1,
                ];
                continue;
            }

            // Next signatures found in buffer
            if ($_final_count === null) {
                // When unbuffered after crash => chose lower range approximate counter
                $_aproximate_count                       = 2 ** $data_to_store[$signature_hash]['count_in_buffer'];
                $data_to_store[$signature_hash]['count'] = $_aproximate_count;

                // Increase after calculate (we log the first error in buffer)
                $data_to_store[$signature_hash]['count_in_buffer']++;
            }
        }

        // Second loop : store data
        foreach ($data_to_store as $signature_hash => $data) {
            // Cron logging
            if (CApp::isCron()) {
                $error_data = "{$data['text']} : {$data['file']} l. {$data['line']}";

                switch ($data['type']) {
                    case 'notice':
                        CCronJobLog::logInfo($error_data);
                        break;
                    case 'exception':
                        CCronJobLog::logWarning($error_data);
                        break;
                    case 'user_error':
                        CCronJobLog::logError($error_data);
                        break;
                    default:
                        // Do nothing
                }
            }

            // Whitelisted ?
            if (in_array($signature_hash, $whitelist_hash, true)) {
                $wl       = new CErrorLogWhiteList();
                $wl->hash = $signature_hash;
                $wl->loadMatchingObject();
                $wl->count += $data['count'];
                $wl->store();

                continue;
            }
            $data["signature_hash"] = $signature_hash;
            // Store
            if (self::isElasticLog()) {
                (new ErrorLogService())->addNewLog($data);
            } else {
                try {
                    CErrorLog::insert(
                        $data['user_id'],
                        $data['server_ip'],
                        $data['time'],
                        $data['request_uid'],
                        $data['type'],
                        $data['text'],
                        $data['file'],
                        $data['line'],
                        $data["signature_hash"],
                        $data['count'],
                        $data['data']
                    );
                } catch (Exception $e) {
                    CApp::log($e->getMessage(), null, CLogger::CHANNEL_ERROR);
                }
            }
        }
        if (self::isElasticLog()) {
            (new ErrorLogService())->bulkStoredErrorLogs();
        }
        // unlink buffer stored
        return @unlink($file_buffer);
    }


    public static function storeCurrentFileBuffer()
    {
        static::storeBuffer(static::$current_file_buffer);
    }

    /**
     * Custom error handler
     *
     * @param string $code Error code
     * @param string $text Error text
     * @param string $file Error file path
     * @param string $line Error line number
     *
     * @throws Exception
     *
     */
    public static function errorHandler($code, $text, $file, $line)
    {
        // Handles the @ case and ignored error
        $error_reporting = error_reporting();

        if (!self::isReportingActive($error_reporting) || in_array($code, CError::$_excluded)) {
            // Log in devtools
            if ((CDevtools::isActive() && CDevtools::getLevel() >= CDevtools::LEVEL_VERY_VERBOSE)
                && !str_contains($file, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)
            ) {
                $type   = CError::$_types[$code];
                $detail = !$error_reporting ? "error_reporting disabled" : "error code excluded";
                CApp::log(
                    "Ignored {$type} ({$detail})",
                    [
                        "code" => $code,
                        "text" => $text,
                        "file" => $file,
                        "line" => $line,
                    ],
                    CLogger::LEVEL_ERROR
                );
            }

            return;
        }

        // Convert to Throwable
        $exception = new ErrorException($text, 0, $code, $file, $line);

        if (static::$request && static::$request->server->get('APP_DEBUG')) {
            // ExceptionListener will handled this exception, log and show debugger or retrun api response
            throw $exception;
        }

        // Log
        static::logException($exception);
        // ... script continue
    }

    private static function isReportingActive(int $error_reporting): bool
    {
        // Before PHP 8.0 @ totaly disable the error_reporting
        if (PHP_VERSION_ID < 80000) {
            return $error_reporting;
        }

        // After php 8.0 the @ no longer silence fatal errors
        // (E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR)
        return $error_reporting !==
            (E_ERROR + E_PARSE + E_CORE_ERROR + E_COMPILE_ERROR + E_USER_ERROR + E_RECOVERABLE_ERROR);
    }

    /**
     * @return null
     */
    public static function getCurrentFileBuffer()
    {
        return static::$current_file_buffer;
    }


    /**
     * Custom throwable handler
     * ExceptionListener do not catch Internal PHP Errors
     *
     * @param Exception $exception
     *
     * @throws Exception
     */
    public static function exceptionHandler($exception)
    {
        static::logException($exception);
    }

    /**
     * @param Exception $exception
     *
     * @param bool      $display_errors
     *
     * @throws Exception
     */
    public static function logException($exception)
    {
        $time = date("Y-m-d H:i:s");

        // User information
        $user_id = null;
        if (class_exists(CAppUI::class, false) && CAppUI::$user) {
            $user = CAppUI::$user;
            if ($user->_id) {
                $user_id = $user->_id;
            }
        }

        // Devtools
        if (CDevtools::isActive()) {
            // when application does not in peace we receive this ErrorException
            if ($exception instanceof ErrorException && $exception->getMessage() === 'Application died unexpectedly') {
                CDevtools::makeTmpFile();
            } else {
                CApp::error($exception);
            }
        }

        // Server IP
        $server_ip = $_SERVER["SERVER_ADDR"] ?? null;

        $file = CMbPath::getRelativePath($exception->getFile());
        $line = $exception->getLine();

        // Stacktrace
        $contexts = $exception->getTrace();
        foreach ($contexts as &$ctx) {
            unset($ctx['args'], $ctx['object']);
        }

        $code = "exception";
        // ErrorException is send by errorHandler, we change the type for ui
        if ($exception instanceof ErrorException) {
            $code = $exception->getSeverity();
            array_shift($contexts);
        }
        $type = self::$_types[$code] ?? null;
        $text = $exception->getMessage();

        // Might noy be ready at the time error is thrown
        $session = $_SESSION ?? [];
        unset($session['AppUI']);
        unset($session['dPcompteRendu']['templateManager']);

        $_all_params = [
            "GET"     => $_GET,
            "POST"    => $_POST,
            "SESSION" => $session,
        ];

        filterInput($_all_params);

        // CApp might not be ready yet as of early error handling
        $request_uid = null;
        if (class_exists(CApp::class, false)) {
            $request_uid = CApp::getRequestUID();
            CApp::$performance[self::$_categories[$code]]++;
        }

        // Signature hash
        $signature_hash = self::generateSignatureHash($text, $type, $file, $line);

        $data_log = BufferizedError::createFromArray(
            [
                BufferizedError::FIELD_CODE           => $code,
                BufferizedError::FIELD_MICROTIME      => microtime(),
                BufferizedError::FIELD_USER_ID        => $user_id,
                BufferizedError::FIELD_SERVER_IP      => $server_ip,
                BufferizedError::FIELD_TIME           => $time,
                BufferizedError::FIELD_REQUEST_UID    => $request_uid,
                BufferizedError::FIELD_TYPE           => $type,
                BufferizedError::FIELD_TEXT           => $text,
                BufferizedError::FIELD_FILE           => $file,
                BufferizedError::FIELD_LINE           => $line,
                BufferizedError::FIELD_SIGNATURE_HASH => $signature_hash,
                BufferizedError::FIELD_DATA           => [
                    "stacktrace"   => $contexts,
                    "param_GET"    => $_all_params["GET"],
                    "param_POST"   => $_all_params["POST"],
                    "session_data" => $_all_params["SESSION"],
                ],
            ]
        );

        // Increase counter (signatures handled)
        if (!array_key_exists($signature_hash, self::$buffered_signatures)) {
            self::$buffered_signatures[$signature_hash] = 1;
        } else {
            self::$buffered_signatures[$signature_hash]++;
        }

        // Error logs buffuring (log only exponential)
        $current_count = self::$buffered_signatures[$signature_hash];
        if ($current_count === 1 || CMbMath::isValidExponential(2, $current_count)) {
            $logger = self::getLogger();
            $logger->log(get_class($exception), $data_log->toArray(), CLogger::LEVEL_ERROR);
        }

        self::$errors_buffer[] = $data_log;
    }

    /**
     * @param string $text
     * @param string $type
     * @param string $file
     * @param int    $line
     *
     * @return string
     */
    public static function generateSignatureHash(string $text, string $type, string $file, int $line): string
    {
        $release_info         = CApp::getVersion()->toArray();
        $revision             = $release_info['revision'] ?? null;
        $text_without_numbers = preg_replace('/\d+/', '', $text);
        $signature            = [
            'type'     => $type,
            'text'     => mb_convert_encoding($text_without_numbers, 'UTF-8', 'ISO-8859-1'),
            'file'     => $file,
            'line'     => $line,
            'revision' => $revision,
        ];

        return md5(serialize($signature));
    }

    /**
     * Echo the bufferized errors
     */
    public static function displayErrors(): void
    {
        if (ini_get("display_errors") && PHP_SAPI !== 'cli') {
            foreach (self::$errors_buffer as $error) {
                echo $error;
            }
        }
    }

    /**
     * @return BufferizedError[]
     */
    public static function getErrorsBuffer(): array
    {
        return self::$errors_buffer;
    }

    public static function setDisplayMode(bool $display): void
    {
        ini_set('display_errors', $display);
    }
}
