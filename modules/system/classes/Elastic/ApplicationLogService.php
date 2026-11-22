<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\System\Elastic;

use Exception;
use Ox\Core\CApp;
use Ox\Core\CAppUI;
use Ox\Core\CLogger;
use Ox\Core\Elastic\ElasticObjectManager;

/**
 * Manipulates application log to store them in Elastic from the hospilibre.log file or directly when logging
 */
class ApplicationLogService
{
    /**
     * @var ApplicationLog[]
     */
    private static array         $application_logs = [];
    private static bool          $elastic_log_register_shutdown = false;
    private ElasticObjectManager $manager;

    /**
     * @param $manager
     */
    public function __construct()
    {
        $this->manager = ElasticObjectManager::getInstance();
    }


    public function addNewLog(string $message, array $data, int $log_level): bool
    {
        self::$application_logs[] = new ApplicationLog($message, $data, $log_level);
        if (count(self::$application_logs) >= CAppUI::conf("application_log_bulking_batch_size")) {
            $this->bulkStoredApplicationLogs();
        }
        if (!self::$elastic_log_register_shutdown) {
            CApp::registerShutdown([$this, "bulkStoredApplicationLogs"]);
            self::$elastic_log_register_shutdown = true;
        }

        return true;
    }

    public function bulkStoredApplicationLogs(): void
    {
        try {
            $this->manager->store(self::$application_logs);
        } catch (Exception $e) {
            trigger_error(
                CAppUI::tr(
                    "ApplicationLog-msg-%s logs cannot be sent to Elasticsearch, falling back to file",
                    count(self::$application_logs)
                )
            );
            self::$application_logs[] = new ApplicationLog(
                CAppUI::tr(
                    "ApplicationLog-msg-%s logs cannot be sent to Elasticsearch, falling back to file",
                    count(self::$application_logs)
                ),
                $e->getTrace(),
                CLogger::LEVEL_WARNING
            );
            // FALLBACK to file logging
            $this->dumpLogsIntoFile(self::$application_logs);
        }
        self::$application_logs = [];
    }

    /**
     * @param ApplicationLog[] $application_logs
     * @param string           $filepath
     *
     * @return void
     */
    public function dumpLogsIntoFile(array $application_logs, string $filepath = ""): bool
    {
        if ($filepath == "") {
            $filepath = CApp::getPathApplicationLog();
        }

        if ($file = fopen($filepath, "a")) {
            foreach ($application_logs as $app) {
                $str = $app->toLogFile();
                fwrite($file, $str . "\n");
            }
        }
        fclose($file);

        return true;
    }
}
