<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\System\Elastic;

use DateTimeImmutable;
use Exception;
use Ox\Core\CApp;
use Ox\Core\CAppUI;
use Ox\Core\CError;
use Ox\Core\CLogger;
use Ox\Core\Elastic\ElasticObjectManager;
use Ox\Core\Elastic\Encoding;
use Hospilibre\System\CErrorLog;

class ErrorLogService
{
    /**
     * @var ErrorLog[]
     */
    private static array         $error_logs                    = [];
    private static bool          $elastic_log_register_shutdown = false;
    private ElasticObjectManager $manager;

    /**
     * @param $manager
     */
    public function __construct()
    {
        $this->manager = ElasticObjectManager::getInstance();
    }


    /**
     * @param array $data
     *
     * @return bool
     * @throws Exception
     */
    public function addNewLog(array $data): bool
    {
        self::$error_logs[] = new ErrorLog($data);
        if (count(self::$error_logs) >= CAppUI::conf("error_log_bulking_batch_size")) {
            $this->bulkStoredErrorLogs();
        }

        return true;
    }

    /**
     * @return void
     * @throws Exception
     */
    public function bulkStoredErrorLogs(): void
    {
        try {
            $this->manager->store(self::$error_logs);
        } catch (Exception $e) {
            $data = [
                "time" => (new DateTimeImmutable("now"))->format("Y-m-d H:i:s"),
                "text" => CAppUI::tr("ErrorLog-msg-%s logs cannot be sent to Elasticsearch, falling back to sql", count(self::$error_logs)),
                "type" => "warning",
                "file" => $e->getFile(),
                "line" => $e->getLine(),
                "data" => [
                    "stacktrace" => $e->getTrace()
                ]
            ];
            $data["signature_hash"] = CError::generateSignatureHash($data["text"], $data["type"], $data["file"], $data["line"]);
            self::$error_logs[] = new ErrorLog($data);
            // FALLBACK to SQL
            $this->dumpLogsIntoSQL(self::$error_logs);
        }
        self::$error_logs = [];
    }

    /**
     * @param ErrorLog[] $error_logs
     *
     * @return void
     * @throws Exception
     */
    public function dumpLogsIntoSQL(array $error_logs): bool
    {
        try {
            foreach ($error_logs as $error_log) {
                $data = $error_log->toArray();
                CErrorLog::insert(
                    $data['user_id'],
                    $data['server_ip'],
                    $data['time'],
                    $data['request_uid'],
                    $data['type'],
                    mb_convert_encoding($data['text'], Encoding::ISO_8859_1()->getValue(), Encoding::UTF_8()->getValue()),
                    $data['file'],
                    $data['line'],
                    $data["signature_hash"],
                    $data['count'],
                    [
                        "stacktrace"   => json_decode($data['stacktrace']),
                        "param_GET"    => json_decode($data['param_GET']),
                        "param_POST"   => json_decode($data['param_POST']),
                        "session_data" => json_decode($data['session_data']),
                    ]
                );
            }
        } catch (Exception $e) {
            CApp::log($e->getMessage(), null, CLogger::CHANNEL_ERROR);
        }

        return true;
    }
}
