<?php
/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Cli\Console\CI;

use Exception;
use Ox\Cli\CommandLinePDO;
use Ox\Cli\HospilibreCommand;
use Ox\Core\CMbConfig;
use Ox\Core\CMbPath;
use Hospilibre\Admin\CUser;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BuildRunner extends HospilibreCommand
{
    public const MASTER_BRANCH = 'master';

    public const KEEP_TABLES = [
        'config_db', // clean manually
        'discipline',
        'firstname_to_gender',
        'functions_hospilibre', // clean manually
        'groups_hospilibre', // clean manually
        'key_metadata',
        'modules',
        'identity_proof_types',
        'moment_unitaire',
        'users', // clean manually
        'users_hospilibre', // clean manually
        'user_preferences', // clean manually
    ];

    public const KEEP_CONFIG_DB = [
        'sourceCode phpunit_user_password',
    ];

    public const ALLOWED_BRANCH_NAME = [
        'master',
        'feature',
        'release',
        'bugfix',
        'test',
    ];

    public const FEATURE_BRANCH_NAME = [
        'feature',
        'bugfix',
        'test',
    ];

    public const MASTER_DATABASE = 'branch_master';

    public const PIPELINE_SOURCE_SCHEDULE = 'schedule';

    protected $ci_project_dir;
    protected $ip_runner;
    protected $db_host;
    protected $db_user;
    protected $db_pass;
    protected $pdo;
    protected $branch_name;
    protected $pipeline_id;
    protected $pipeline_source;
    private   $database_name;

    /**
     * @see parent::configure()
     */
    protected function configure(): void
    {
        $this
            ->setName('ox-ci:build-runner')
            ->setDescription('Build Runner')
            ->addOption(
                'ci_project_dir',
                null,
                InputOption::VALUE_REQUIRED,
                'The full path where the repository is cloned'
            )->addOption(
                'db_host',
                null,
                InputOption::VALUE_REQUIRED,
                'The db host name'
            )->addOption(
                'db_user',
                null,
                InputOption::VALUE_REQUIRED,
                'The db username'
            )->addOption(
                'db_pass',
                null,
                InputOption::VALUE_REQUIRED,
                'The db password'
            )->addOption(
                'branch_name',
                null,
                InputOption::VALUE_REQUIRED,
                'The branch name'
            )->addOption(
                'ip_runner',
                null,
                InputOption::VALUE_OPTIONAL,
                'Runner ip adress'
            )->addOption(
                'pipeline_id',
                null,
                InputOption::VALUE_OPTIONAL,
                'Pipeline id'
            )->addOption(
                'pipeline_source',
                null,
                InputOption::VALUE_OPTIONAL,
                'Pipeline source'
            );
    }

    /**
     * @see parent::showHeader()
     */
    protected function showHeader()
    {
        $this->out($this->output, '<fg=red;bg=black>Deploy GitLab-runner</fg=red;bg=black>');
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output          = $output;
        $this->input           = $input;
        $this->path            = dirname(__DIR__, 4);
        $this->ci_project_dir  = $input->getOption('ci_project_dir');
        $this->ip_runner       = $input->getOption('ip_runner');
        $this->db_host         = $input->getOption('db_host');
        $this->db_user         = $input->getOption('db_user');
        $this->db_pass         = $input->getOption('db_pass');
        $this->branch_name     = $input->getOption('branch_name');
        $this->pipeline_id     = $input->getOption('pipeline_id');
        $this->pipeline_source = $input->getOption('pipeline_source');
        $this->showHeader();

        // Check allowed branch
        $this->checkAllowedBranchName();

        // Check path
        if (!is_dir($this->path)) {
            throw new InvalidArgumentException("'{$this->path}' is not a valid directory.");
        }

        $this->out($this->output, $this->ci_project_dir);

        // database name
        $this->setDatabaseName();

        // generate includes/config.php
        $this->generateConfig();

        // pdo
        $this->setPDO();

        // Generate database
        if ($this->branch_name !== static::MASTER_BRANCH) {
            $this->generateDatabase();
        }

        // Use Database
        $this->pdo->query("use " . $this->database_name);

        // Drop & Truncate tables
        $this->resetDatabase();

        // generate tmp
        $this->generateTmp();

        return self::SUCCESS;
    }

    private function resetDatabase()
    {
        $statement = $this->pdo->query("SHOW TABLES;");
        $tables    = $statement->fetchAll(\PDO::FETCH_COLUMN, 0);

        $this->out($this->output, "Droping '{$this->database_name}' tables ...");
        $count_drop = 0;
        foreach ($tables as $key => $table) {
            if (str_starts_with($table, 'ex_object_') && $table !== 'ex_object_picture') {
                $this->pdo->query("DROP TABLE {$table};");
                $count_drop++;
                unset($tables[$key]);
            }
        }
        $this->out($this->output, "Drop {$count_drop} tables on '{$this->database_name}'");

        $this->out($this->output, "Truncating '{$this->database_name}' tables ...");
        foreach ($tables as $table) {
            if (!in_array($table, static::KEEP_TABLES)) {
                $this->pdo->query("TRUNCATE TABLE {$table};");
            }
        }
        $count = count($tables);
        $this->out($this->output, "Truncate {$count} tables on '{$this->database_name}'");

        $user_phpunit = CUser::USER_PHPUNIT;
        $user_admin   = CUser::USER_ADMIN;

        $this->out($this->output, "Deleting whitelist tables ...");
        $queries = [
            "DELETE FROM users where template <> '1' and user_username not in ('{$user_phpunit}', '{$user_admin}');",
            "DELETE FROM users_hospilibre where user_id not in (SELECT user_id from users);",
            "DELETE FROM functions_hospilibre where function_id not in (SELECT function_id from users_hospilibre);",
            "DELETE FROM groups_hospilibre where group_id not in (SELECT group_id from functions_hospilibre);",
            "DELETE FROM user_preferences where user_id not in (SELECT user_id from users) and user_id is not null;",
            "DELETE FROM config_db where key not in (" . implode(',', static::KEEP_CONFIG_DB) . ");",
        ];

        foreach ($queries as $query) {
            $this->pdo->query($query);
        }
        $count = count($queries);
        $this->out($this->output, "Delete {$count} tables on '{$this->database_name}'");

        $this->out($this->output, "Add perm_module for users");
        $this->pdo->query(
            "INSERT INTO perm_module (user_id, mod_id, permission, view) SELECT  user_id, NULL, 2, 2 FROM users where template != '1';"
        );
    }


    private function checkAllowedBranchName()
    {
        $is_allowed_branch = false;
        foreach (static::ALLOWED_BRANCH_NAME as $name) {
            if (strpos($this->branch_name, $name) === 0) {
                $is_allowed_branch = true;
            }
        }

        if (!$is_allowed_branch) {
            $allowed_branch = implode('|', static::ALLOWED_BRANCH_NAME);
            throw new LogicException("Invalid branch name '{$this->branch_name}' allowed ({$allowed_branch}).");
        }
    }


    private function setPDO()
    {
        $this->pdo = new CommandLinePDO($this->db_host, $this->db_user, $this->db_pass);
    }

    private function setDatabaseName()
    {
        $this->database_name = $this->branch_name === static::MASTER_BRANCH ? static::MASTER_DATABASE : 'pipeline_' . $this->pipeline_id;
        $this->out($this->output, "Database set as " . $this->database_name);
    }

    /**
     * @return void
     */
    private function generateDatabase()
    {
        $this->out($this->output, "Generating database '{$this->database_name}'...");
        $this->createDatabase($this->database_name);

        // Origin database
        $origin_database = $this->getOriginDatabase();
        if (!$this->pdo->isDatabaseExists($origin_database)) {
            $this->out($this->output, "Generating new release database '{$origin_database}'...");
            // New release branch just created : create origin database from master
            $this->createDatabase($origin_database);
            $this->dumpDatabase(static::MASTER_DATABASE, $origin_database);
        }

        // Dump from origin schema database
        $this->dumpDatabase($origin_database, $this->database_name);
    }

    /**
     * @param $from_database
     * @param $to_database
     */
    private function dumpDatabase(string $from_database, string $to_database)
    {
        $cmd = "mysqldump -h {$this->db_host} -u {$this->db_user} --password={$this->db_pass} {$from_database} |
     mysql -h {$this->db_host} -u {$this->db_user} --password={$this->db_pass} {$to_database}";
        exec($cmd, $output);

        if (!empty($output)) {
            echo $cmd;
            throw new LogicException("Unable to copy database '{$to_database}' from {$from_database}.");
        }

        $this->out($this->output, "Database '{$to_database}' dumped from '{$from_database}'.");
    }

    /**
     * @param string $database
     */
    private function createDatabase(string $database)
    {
        if ($this->pdo->createDatabase($database)) {
            $this->out($this->output, "Database '{$database} created.'");
        } else {
            throw new LogicException("Unable to create new database '{$database}'.");
        }
    }

    /**
     * @return string
     */
    private function getOriginDatabase(): string
    {
        if (static::isFeatureBranch($this->branch_name)) {
            return static::MASTER_DATABASE;
        }

        return 'branch_' . preg_replace('/\W/', '_', $this->branch_name);
    }

    /**
     * @return void
     * @throws Exception
     */
    private function generateConfig()
    {
        $this->out($this->output, "Generating config.php ...");

        $file = $this->ci_project_dir . DIRECTORY_SEPARATOR . "includes" . DIRECTORY_SEPARATOR . "config.php";

        touch($file);

        $configs = $this->getConfigs();

        foreach ($configs as $key => $value) {
            $config = new CMbConfig();
            $config->set($key, $value);
            $config->update($config->values);
        }
    }

    /**
     * @return void
     */
    private function generateTmp()
    {
        $this->out($this->output, "Generating tmp folder ...");
        CMbPath::forceDir("tmp");
        chmod("tmp", 0777);
    }


    /**
     * @param string $database_name
     *
     * @return bool
     */
    public static function isFeatureDatabase(string $database_name): bool
    {
        $result = false;
        foreach (static::FEATURE_BRANCH_NAME as $_name) {
            if (strpos($database_name, 'branch_' . $_name) === 0) {
                $result = true;
            }
        }

        return $result;
    }

    /**
     * @param string $database_name
     *
     * @return bool
     */
    public static function isFeatureBranch(string $branch_name): bool
    {
        $result = false;
        foreach (static::FEATURE_BRANCH_NAME as $_name) {
            if (strpos($branch_name, $_name) === 0) {
                $result = true;
            }
        }

        return $result;
    }


    /**
     * @return array
     */
    private function getConfigs()
    {
        $root_dir  = $this->path;
        $ip_runner = $this->ip_runner ?: 'localhost';
        $base_url  = str_replace('/home/gitlab-runner/', "http://{$ip_runner}/", $this->path);

        $dPconfig                 = [];
        $dPconfig['root_dir']     = $root_dir;
        $dPconfig['product_name'] = 'Hospilibre';
        $dPconfig['company_name'] = 'mediboard.org';
        $dPconfig['page_title']   = 'Hospilibre SIH';
        $dPconfig['base_url']     = $base_url;
        //    $dPconfig['external_url']                                = $base_url;
        $dPconfig['instance_role']                                   = 'qualif';
        $dPconfig['db_security_flag']                                = '1';
        $dPconfig['config_db']                                       = '1';
        $dPconfig['shared_memory']                                   = 'apcu';
        $dPconfig['shared_memory_distributed']                       = '';
        $dPconfig['shared_memory_params']                            = '';
        $dPconfig['session_handler']                                 = 'files';
        $dPconfig['session_handler_mutex_type']                      = 'files';
        $dPconfig['mutex_drivers CMbRedisMutex']                     = '0';
        $dPconfig['mutex_drivers CMbAPCMutex']                       = '1';
        $dPconfig['mutex_drivers CMbFileMutex']                      = '1';
        $dPconfig['mutex_drivers_params CMbRedisMutex']              = '127.0.0.1:6379';
        $dPconfig['mutex_drivers_params CMbFileMutex']               = '';
        $dPconfig['app_master_key_filepath']                         = '';
        $dPconfig['app_public_key_filepath']                         = '';
        $dPconfig['app_private_key_filepath']                        = '';
        $dPconfig['offline']                                         = '0';
        $dPconfig['offline_non_admin']                               = '0';
        $dPconfig['offline_time_start']                              = '';
        $dPconfig['offline_time_end']                                = '';
        $dPconfig['sourceCode selenium_browsers windows_chrome']     = '1';
        $dPconfig['admin ProSanteConnect enable_psc_authentication'] = '1';
        $dPconfig['admin ProSanteConnect enable_login_button']       = '1';

        $dPconfig['db']['std']['dbtype'] = 'mysql';
        $dPconfig['db']['std']['dbhost'] = $this->db_host;
        $dPconfig['db']['std']['dbname'] = $this->database_name;
        $dPconfig['db']['std']['dbuser'] = $this->db_user;
        $dPconfig['db']['std']['dbpass'] = $this->db_pass;

        $bdd = [
            'ameli',
            'atih',
            'bcb1',
            'bcb2',
            'ccam',
            'ccamV2',
            'cim10',
            'cisp',
            'cdarr',
            'csarr',
            'drc',
            'hl7v2',
            'hospi_diag',
            'loinc',
            'lpp',
            'presta_ssr',
            'rpps_import',
            'sae',
            'sesam-vitale',
            'snomed',
            'ASIP',
            'INSEE',
        ];

        foreach ($bdd as $_bdd_name) {
            $dPconfig['db'][$_bdd_name]['dbtype'] = 'mysql';
            $dPconfig['db'][$_bdd_name]['dbhost'] = $this->db_host;
            $dPconfig['db'][$_bdd_name]['dbname'] = strtolower($_bdd_name);
            $dPconfig['db'][$_bdd_name]['dbuser'] = $this->db_user;
            $dPconfig['db'][$_bdd_name]['dbpass'] = $this->db_pass;
        }

        // Add BCB1 datasource
        $dPconfig['bcb']['CBcbObject']['dsn'] = 'bcb1';

        // hack sesam vitale
        $dPconfig['db']['sesam-vitale']['dbname'] = 'sesam_vitale';

        // Elastic config
        $dPconfig['elastic']['search']['elastic_index'] = 'search-' . $this->pipeline_id;
        $dPconfig['elastic']['search']['elastic_host']  = $this->db_host;
        $dPconfig['elastic']['search']['elastic_port']  = '9200';

        $dPconfig['elastic']['application-log']['elastic_index'] = 'application-log-' . $this->pipeline_id;
        $dPconfig['elastic']['application-log']['elastic_host']  = $this->db_host;
        $dPconfig['elastic']['application-log']['elastic_port']  = '9200';

        $dPconfig['elastic']['error-log']['elastic_index'] = 'error-log'  . $this->pipeline_id;
        $dPconfig['elastic']['error-log']['elastic_host']  = $this->db_host;
        $dPconfig['elastic']['error-log']['elastic_port']  = '9200';

        $dPconfig['elastic']['query-digests']['elastic_index'] = 'query-digests-' . $this->pipeline_id;
        $dPconfig['elastic']['query-digests']['elastic_host']  = $this->db_host;
        $dPconfig['elastic']['query-digests']['elastic_port']  = '9200';

        $dPconfig['elastic']['test_index_elastic_hospilibre']['elastic_index'] = 'test_index_elastic_hospilibre-' . $this->pipeline_id;
        $dPconfig['elastic']['test_index_elastic_hospilibre']['elastic_host']  = $this->db_host;
        $dPconfig['elastic']['test_index_elastic_hospilibre']['elastic_port']  = '9200';

        // Fhir Jar (Java) Validator from https://github.com/hapifhir/org.hl7.fhir.core/releases/latest/download/validator_cli.jar
        $dPconfig['sourceCode']['fhir']['fhir_validator_path'] = '/home/gitlab-runner/fhir';

        return $dPconfig;
    }

}
