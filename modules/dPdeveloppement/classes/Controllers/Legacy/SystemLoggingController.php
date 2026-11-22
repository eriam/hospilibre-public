<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Developpement\Controllers\Legacy;

use DateTimeImmutable;
use Ox\Core\CApp;
use Ox\Core\CAppUI;
use Ox\Core\CError;
use Ox\Core\CLegacyController;
use Ox\Core\CLogger;
use Ox\Core\CMbDT;
use Ox\Core\CMbPath;
use Ox\Core\CMbString;
use Ox\Core\CView;
use Ox\Core\Elastic\ElasticObjectManager;
use Ox\Core\Elastic\ElasticObjectRepositories;
use Ox\Core\Elastic\Exceptions\ElasticClientException;
use Ox\Core\Elastic\Exceptions\ElasticException;
use Hospilibre\Admin\CUser;
use Hospilibre\System\CErrorLog;
use Hospilibre\System\Elastic\ApplicationLog;
use Hospilibre\System\Elastic\ApplicationLogRepository;
use Throwable;

class SystemLoggingController extends CLegacyController
{
    public function listApplicationLogUsingFile(): void
    {
        $this->checkPermRead();

        $file           = CApp::getPathApplicationLog();
        $file_grep      = str_replace(".log", ".grep.log", $file);
        $log_start      = CView::get("log_start", "str");
        $grep_search    = CView::get("grep_search", "str");
        $grep_regex     = CView::get("grep_regex", "bool default|0");
        $grep_sensitive = CView::get("grep_sensitive", "bool default|0");
        $session_grep   = isset($_SESSION['dPdeveloppement_log_grep']) ? $_SESSION['dPdeveloppement_log_grep'] : "";
        $time_start     = microtime(true);
        $words          = [];

        if ($grep_search) {
            if ($grep_search != $session_grep || true) {
                // new grep file
                $_SESSION['dPdeveloppement_log_grep'] = $grep_search;

                $cmd = "grep ";
                if (!$grep_sensitive) {
                    $cmd .= " -i ";
                }

                if (!$grep_regex) {
                    if (strpos($grep_search, " ") !== false) {
                        $words = array_unique(explode(" ", $grep_search));
                    } else {
                        $words = [$grep_search];
                    }

                    $cmd_repeat = ' | ' . $cmd;
                    foreach ($words as $key => $_word) {
                        $_word = str_replace(".", "\.", $_word);
                        $_word = str_replace("[", "\[", $_word);
                        $_word = str_replace("]", "\]", $_word);

                        if ($key === 0) {
                            $cmd .= '"' . $_word . '"' . ' ' . $file;
                        } else {
                            $cmd .= $cmd_repeat . '"' . $_word . '"';
                        }
                    }
                } else {
                    $cmd .= " \"{$grep_search}\" {$file} ";
                }

                $cmd .= " > {$file_grep}";
                shell_exec($cmd);
            }

            $file = $file_grep;
        } else {
            $_SESSION['dPdeveloppement_log_grep'] = $grep_search;
        }

        CView::checkin();

        $nb_lines     = 1000;
        $logs_display = [];

        $pattern = "/\[(?P<date>.*?)\] \[(?P<level>\w+)\] (?P<message>.*?) \[context:(?P<context>.*\]?)\] \[extra:(?P<extra>.*?)\]/";

        $logs = CMbPath::tailWithSkip($file, $nb_lines, $log_start);
        $logs = explode("\n", $logs);
        $logs = array_reverse($logs);
        $logs = array_filter($logs);

        $nb_logs = count($logs);

        $exec_time = microtime(true) - $time_start;
        $exec_time = round($exec_time, 3) * 1000;

        foreach ($logs as $_key => $_log) {
            // init
            $parsed_application_log = new ApplicationLog();
            preg_match($pattern, $_log, $data);


            if (array_key_exists("date", $data)) {
                $parsed_application_log->setDate(DateTimeImmutable::createFromFormat("Y-m-d H:i:s.u", $data["date"]));
            }

            if (array_key_exists("level", $data)) {
                $parsed_application_log->setLogLevel($data["level"]);
            }

            $parsed_application_log->setMessage($data["message"] ?? "");

            if (array_key_exists("context", $data)) {
                $parsed_application_log->setContext($data["context"]);
            }

            if (array_key_exists("extra", $data)) {
                $parsed_application_log->setExtra($data["extra"]);
            }

            $logs_display[] = $parsed_application_log->prepareToRender();
        }

        // hightlight
        if (!$grep_regex && !empty($words)) {
            foreach ($logs_display as $_key_log => &$_log) {
                foreach ($words as $_word) {
                    $_log['date']    = $this->highlight($_word, $_log['date']);
                    $_log['level']   = $this->highlight($_word, $_log['level']);
                    $_log['message'] = $this->highlight($_word, $_log['message']);

                    if ($_log['context_json'] !== $this->highlight($_word, $_log['context_json'])) {
                        $_log['context'] = $this->highlight($_log['context'], $_log['context']);
                    }

                    if ($_log['extra_json'] !== $this->highlight($_word, $_log['extra_json'])) {
                        $_log['extra'] = $this->highlight($_log['extra'], $_log['extra']);
                    }
                }
            }
        }

        $this->renderSmarty(
            "inc_list_logs",
            [
                "logs"      => $logs_display,
                "nb_logs"   => $nb_logs,
                "exec_time" => $exec_time,
            ]
        );
    }


    public function listApplicationLogUsingElastic(): void
    {
        $this->checkPermRead();

        $log_start      = CView::get("log_start", "str");
        $grep_search    = CView::get("grep_search", "str");
        $grep_regex     = CView::get("grep_regex", "bool default|0");
        $grep_sensitive = CView::get("grep_sensitive", "bool default|0");
        $time_start     = microtime(true);

        CView::checkin();

        $logs_display = [];
        $nb_logs      = 1000;
        try {
            $repository = new ApplicationLogRepository();
        } catch (ElasticClientException $e) {
            CAppUI::stepAjax(
                "ElasticIndexManager-error-Can not create Elasticsearch client without dsn configuration",
                UI_MSG_ERROR
            );
        }

        $logs = [];

        // TODO : implements matching case
        try {
            if ($grep_search == "") {
                $logs = $repository->list(
                    $log_start,
                    $nb_logs,
                );
            } elseif ($grep_regex == false) {
                $logs = $repository->searchWithHighlighting(
                    $nb_logs,
                    $grep_search,
                    ["message^2", "context"],
                    $log_start,
                    ElasticObjectRepositories::SORTING_DATE_DESC
                );
            } else {
                $logs = $repository->searchWithRegexAndHighlighting(
                    $nb_logs,
                    $grep_search,
                    ["message", "context"],
                    $grep_sensitive,
                    $log_start,
                    ElasticObjectRepositories::SORTING_DATE_DESC
                );
            }
        } catch (ElasticException $e) {
            CApp::log(
                CAppUI::tr("ElasticIndexManager-error-Connection failed"),
                ["message" => $e->getMessage()],
                CLogger::LEVEL_ERROR
            );
            CAppUI::stepAjax("ElasticIndexManager-error-Connection failed", UI_MSG_ERROR);
        }

        $nb_logs = count($logs);

        foreach ($logs as $log) {
            $logs_display[] = $log->prepareToRender();
        }

        $exec_time = microtime(true) - $time_start;
        $exec_time = round($exec_time, 3) * 1000;


        $this->renderSmarty(
            "inc_list_logs",
            [
                "logs"      => $logs_display,
                "nb_logs"   => $nb_logs,
                "exec_time" => $exec_time,
            ]
        );
    }

    public function showLogInfos(): void
    {
        $this->checkPermEdit();

        $json = CView::post("json", "str");

        CView::checkin();

        $json = urldecode($json);
        $log  = unserialize($json);

        $date    = isset($log['date']) ? $log['date'] : null;
        $level   = isset($log['level']) ? $log['level'] : null;
        $color   = isset($log['color']) ? $log['color'] : null;
        $message = isset($log['message']) ? $log['message'] : null;

        if (isset($log['extra_json'])) {
            $extra = json_decode($log['extra_json'], true);
        } else {
            $extra = null;
        }

        if (isset($log['context_json'])) {
            $context = json_decode($log['context_json'], true);
            $context = CLogger::decodeContext($context);
        } else {
            $context = null;
        }

        $logs_display = [
            "Date de création" => $date,
            "Level"            => $level,
            "Message"          => $message,
            "Context"          => print_r($context, true),
            "Extra"            => print_r($extra, true),
        ];

        $this->renderSmarty(
            "inc_infos_log",
            [
                "logs" => $logs_display,
            ]
        );
    }


    public function view_logs(): void
    {
        $this->checkPermRead();

        // Error (db)
        $spec_error_type = [
            "str",
            "default" => [],
        ];

        $error_type = CView::get("error_type", $spec_error_type, true);
        $text       = CView::get("text", "str", true);
        $server_ip  = CView::get("server_ip", "str", true);

        $spec_datetime_min = [
            "dateTime",
            "default" => CMbDT::dateTime("-1 WEEK"),
        ];

        $datetime_min  = CView::get("_datetime_min", $spec_datetime_min, true);
        $datetime_max  = CView::get("_datetime_max", "dateTime", true);
        $order_by      = CView::get("order_by", "enum list|date|quantity", true);
        $group_similar = CView::get("group_similar", "enum list|similar|signature|no default|similar", true);
        $user_id       = CView::get("user_id", "ref class|CMediusers", true);
        $human         = CView::get("human", "bool", true);
        $robot         = CView::get("robot", "bool", true);

        CView::checkin();

        $error_log                = new CErrorLog();
        $error_log->text          = $text;
        $error_log->server_ip     = $server_ip;
        $error_log->_datetime_min = $datetime_min;
        $error_log->_datetime_max = $datetime_max;

        // Error (buffer)
        $files_error_buffer     = CError::globWaitingBuffer();
        $count_error_log_buffer = CError::countWaitingBuffer();

        // log (file)
        $log_size       = 0;
        $file           = CApp::getPathApplicationLog();
        $first_log_date = null;
        $last_log_date  = null;

        if (file_exists($file)) {
            // Last logs
            $logs          = CMbPath::tailCustom($file, 1);
            $logs          = explode("\n", $logs);
            $logs          = is_array($logs) ? $logs : [];
            $last_log      = $logs[0];
            $pos           = strpos($last_log, ']');
            $last_log_date = substr($last_log, 1, $pos - 1);
            // first log
            $handle = fopen($file, "r");
            if ($handle) {
                $line           = fgets($handle);
                $pos            = strpos($line, ']');
                $first_log_date = substr($line, 1, $pos - 1);
                $log_size       = filesize($file);
                fclose($handle);
            }
        }

        // log (elastic)
        $elastic_up             = false;
        $elastic_first_log_date = null;
        $elastic_last_log_date  = null;
        $elastic_log_size       = 0;
        if (CApp::isElasticLog()) {
            try {
                $elastic_up       = true;
                $index            = (new ApplicationLog())->getSettings()->getIndexName();
                $repo             = new ApplicationLogRepository();
                $elastic_log_size = $repo->count();
                if ($elastic_log_size > 0) {
                    try {
                        $elastic_first_log_date = $repo->first(1)[0]->getDate()->format("Y-m-d H:i:s.u");
                        $elastic_last_log_date  = $repo->last(1)[0]->getDate()->format("Y-m-d H:i:s.u");
                    } catch (ElasticException $e) {
                    }
                }
            } catch (Throwable $e) {
                $elastic_up       = false;
                $elastic_log_size = 0;
            }
        }

        // Récupération de la liste des utilisateurs disponibles
        $user           = new CUser();
        $user->template = "0";
        $order          = "user_last_name, user_first_name";
        $list_users     = $user->loadMatchingList($order);

        // Recherche dans les logs
        $enable_grep = stripos(PHP_OS, "WIN") === 0 ? false : true;

        // Création du template
        $this->renderSmarty(
            "view_logs",
            [
                "elastic_up"             => $elastic_up,
                "error_log"              => $error_log,
                "count_error_log_buffer" => $count_error_log_buffer,
                "error_type"             => $error_type,
                "server_ip"              => $server_ip,
                "order_by"               => $order_by,
                "group_similar"          => $group_similar,
                "error_types"            => CError::getErrorTypesByCategory(),
                "user_id"                => $user_id,
                "list_users"             => $list_users,
                "human"                  => $human,
                "robot"                  => $robot,
                "first_log_date"         => $first_log_date,
                "last_log_date"          => $last_log_date,
                "log_size"               => CMbString::toDecaBinary($log_size),
                "log_file_path"          => $file,
                "elastic_first_log_date" => $elastic_first_log_date,
                "elastic_last_log_date"  => $elastic_last_log_date,
                "elastic_log_size"       => $elastic_log_size,
                "index"                  => $index ?? "",
                "enable_grep"            => $enable_grep,
            ]
        );
    }

    public function downloadLogFile()
    {
        $this->checkPermAdmin();

        $mode = CView::get("elasticsearch_or_file", "bool default|0");

        ob_end_clean();

        $file = CApp::getPathApplicationLog();

        // String
        if ($mode === "elasticsearch") {
            $file = str_replace(".log", "-elastic.log", $file);
            try {
                (new ApplicationLogRepository())->dumpIndexIntoFile($file);
            } catch (ElasticException $e) {
                CAppUI::stepAjax("No logs in Elasticsearch !");
                CApp::rip();
            }
        }

        if (file_exists($file)) {
            header("Content-Type: text/html");
            header("Content-Length: " . filesize($file));
            header("Content-Disposition: attachment; filename=application.log");

            readfile($file);
        } else {
            CAppUI::stepAjax("No file : " . $file);
        }


        CApp::rip();
    }

    /**
     * @param $word
     * @param $subject
     *
     * @return string|string[]|null
     */
    private function highlight($word, $subject)
    {
        $pos = stripos($subject, $word);

        if ($pos === false) {
            return $subject;
        }

        $replace = substr($subject, $pos, strlen($word));

        return str_ireplace($word, '<span style="background-color:yellow">' . $replace . '</span>', $subject);
    }


    public function deleteApplicationLogFile(): void
    {
        $this->checkPermEdit();

        CView::checkin();

        $filename      = CApp::getPathApplicationLog();
        $log_size_deca = CMbString::toDecaBinary(0);

        @unlink($filename);

        CAppUI::callbackAjax("Control.Tabs.setTabCount", "log-tab", $log_size_deca);
    }

    public function deleteApplicationLogElasticsearchIndex(): void
    {
        $this->checkPermEdit();

        CView::checkin();

        $manager = ElasticObjectManager::getInstance();
        $obj     = new ApplicationLog();

        try {
            $manager->deleteIndex($obj);
        } catch (ElasticClientException $e) {
        } catch (ElasticException $e) {
            CApp::log("ElasticObjectManager-error-Can not delete Elastic index", [], CLogger::LEVEL_ERROR);
            CAppUI::stepAjax("ElasticObjectManager-error-Can not delete Elastic index", UI_MSG_ERROR);
        }
    }
}
