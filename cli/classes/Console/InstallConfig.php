<?php

/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Cli\Console;

use Exception;
use Ox\Cli\HospilibreCommand;
use Ox\Core\CClassMap;
use Ox\Core\CMbConfig;
use Ox\Core\Import\CExternalDataSourceImport;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

use const DIRECTORY_SEPARATOR;

/**
 * Class InstallConfig
 *
 * @package Ox\Cli\Console
 */
class InstallConfig extends HospilibreCommand
{
    /** @var OutputInterface */
    protected $output;

    /** @var InputInterface */
    protected $input;

    /** @var SymfonyStyle */
    protected $io;

    /** @var string */
    protected $path;

    /** @var QuestionHelper */
    protected $question_helper;

    /** @var bool */
    protected $externals;

    /** @var bool */
    protected $ci_mode;

    /** @var string */
    protected $ip = 'localhost';

    /** @var string */
    protected $db_host;

    /** @var string */
    protected $db_name;

    /** @var string */
    protected $db_user;

    /** @var string */
    protected $db_pass;

    /** @var string */
    protected $es_host;

    /** @var string */
    protected $es_port;

    /** @var string */
    protected $pipeline_id;

    /** @var array */
    protected $pipeline_externals;

    /** @var array */
    private $configs = [];

    /** @var CMbConfig */
    private $mbConfig;

    /**
     * @see parent::configure()
     */
    protected function configure(): void
    {
        $this
            ->setName('ox-install:config')
            ->setDescription('Install OX configuration')
            ->addOption(
                'path',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Working copy root',
                dirname(__DIR__, 3)
            )->addOption(
                'externals',
                null,
                InputOption::VALUE_NONE,
                'Create configuration for external datasource',
            )->addOption(
                'ci-mode',
                null,
                InputOption::VALUE_NONE,
                'CI mode',
            )->addOption(
                'db-host',
                null,
                InputOption::VALUE_OPTIONAL,
                'The db host name'
            )->addOption(
                'db-user',
                null,
                InputOption::VALUE_OPTIONAL,
                'The db username'
            )->addOption(
                'db-pass',
                null,
                InputOption::VALUE_OPTIONAL,
                'The db password'
            )->addOption(
                'es-host',
                null,
                InputOption::VALUE_OPTIONAL,
                'The es host name'
            )->addOption(
                'es-port',
                null,
                InputOption::VALUE_OPTIONAL,
                'The es port'
            )->addOption(
                'ip',
                null,
                InputOption::VALUE_OPTIONAL,
                'IP address'
            )->addOption(
                'pipeline-id',
                null,
                InputOption::VALUE_OPTIONAL,
                'Pipeline id'
            )->addOption(
                'pipeline-externals',
                null,
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Externals for each pipeline (CI Mode only)',
            );
    }

    /**
     * @see parent::showHeader()
     */
    protected function showHeader(): void
    {
        $this->out($this->output, '<fg=yellow;bg=black>OX configurations setting</fg=yellow;bg=black>');
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
        $this->output             = $output;
        $this->input              = $input;
        $this->io                 = new SymfonyStyle($this->input, $this->output);
        $this->question_helper    = $this->getHelper('question');
        $this->path               = $input->getOption('path');
        $this->externals          = $input->getOption('externals');
        
        $this->ci_mode            = $input->getOption('ci-mode');
        $this->ip                 = $input->getOption('ip');
        $this->db_host            = $input->getOption('db-host');
        $this->db_user            = $input->getOption('db-user');
        $this->db_pass            = $input->getOption('db-pass');
        $this->es_host            = $input->getOption('es-host');
        $this->es_port            = $input->getOption('es-port');
        $this->pipeline_id        = $input->getOption('pipeline-id');
        $this->pipeline_externals = $input->getOption('pipeline-externals');

        $this->showHeader();

        if (!is_dir($this->path)) {
            throw new InvalidArgumentException("'$this->path' is not a valid directory.");
        }

        $config_file = $this->path . DIRECTORY_SEPARATOR . CMbConfig::CONFIG_FILE;
        if (file_exists($config_file)) {
            throw new LogicException("The configuration file {$config_file} already exists.");
        }

        if ($this->ci_mode && !empty($this->pipeline_id)) {
            $this->db_name = 'pipeline_' . $this->pipeline_id;
        }

        $this->mbConfig = new CMbConfig($this->path);

        if (!$this->askQuestions()) {
            return self::FAILURE;
        }

        $make_config = $this
            ->addDefaultConfigs()
            ->addCIConfigs()
            ->addElasticSearchConfigs()
            ->addExternalConfigs()
            ->convertConfigs()
            ->storeConfigs();

        if (!$make_config || !$this->mbConfig->isConfigFileExists()) {
            throw new LogicException('Configuration registrations failed');
        }

        $this->io->success('Configurations successfully saved !');

        return self::SUCCESS;
    }

    private function addElasticSearchConfigs(): self
    {
        $indexes = [
            'search',
            'application-log',
            'error-log',
            'query-digests',
            'test_index_elastic_hospilibre',
        ];

        $config = [];

        foreach ($indexes as $index) {

            $index_name = $index;
            if ($this->ci_mode && !empty($this->pipeline_id)) {
                $index_name .= '-' . $this->pipeline_id;
            }

            $config["elastic"][$index] = [
                'elastic_index' => $index_name,
                'elastic_host'  => $this->configs['elastic_host'],
                'elastic_port'  => $this->configs['elastic_port'],
            ];
        }

        $this->configs = array_merge_recursive($this->configs, $config);

        return $this;
    }

    /**
     * @return self
     * @throws Exception
     */
    private function addCIConfigs(): self
    {
        $runner_dir = '/home/gitlab-runner';

        if ($this->ci_mode) {
            $ip           = $this->ip ?: 'localhost';
            if (strpos($this->path, $runner_dir) !== false) {
                $this->configs['base_url']     = str_replace($runner_dir, "http://{$ip}", $this->path);
                $this->configs['external_url'] = str_replace($runner_dir, "http://localhost", $this->path);
            }

            $this->configs = array_merge_recursive(
                $this->configs,
                [
                    'db' => [
                        'bcb' => [
                            'dbtype' => 'mysql',
                            'dbhost' => $this->configs['database_host'],
                            'dbname' => 'bcb1',
                            'dbuser' => $this->configs['database_user'],
                            'dbpass' => $this->configs['database_pass'],
                        ]
                    ],
                    'bcb' => [
                        'CBcbObject' => [
                            'dsn' => 'bcb',
                        ]
                    ],
                    'sourceCode' => [
                        'fhir' => [
                            'fhir_validator_path' => $runner_dir . DIRECTORY_SEPARATOR . 'fhir',
                        ],
                        'phpunit_user_password' => 'qT3hsl'
                    ]
                ]
            );
        }

        return $this;
    }

    /**
     * @return self
     * @throws Exception
     */
    private function addExternalConfigs(): self
    {
        if ($this->externals) {
            $externalConfig = [];

            $externalClasses = CClassMap::getInstance()->getClassChildren(CExternalDataSourceImport::class);
            $externals = [];

            /** @var string $importClass */
            foreach ($externalClasses as $externalClass) {
                /** @var CExternalDataSourceImport $import */
                $externals[] = new $externalClass();
            }

            /** @var CExternalDataSourceImport $external */
            foreach ($externals as $external) {
                $name = $this->getExternalDatabaseName($external);

                $externalConfig["db"][$external->getSourceName()] = [
                    'dbtype' => 'mysql',
                    'dbhost' => $this->configs['database_host'],
                    'dbname' => $name,
                    'dbuser' => $this->configs['database_user'],
                    'dbpass' => $this->configs['database_pass'],
                ];
            }

            $this->configs = array_merge_recursive($this->configs, $externalConfig);
        }

        return $this;
    }

    /**
     * @return self
     */
    private function addDefaultConfigs(): self
    {
        $this->configs = array_merge($this->configs, [
            'config_db'            => 0,
            'servers_ip'           => null,
            'offline'              => 0,
            'offline_non_admin'    => 0,
            'db'                   => [
                'std' => [
                    'dbtype' => 'mysql',
                ],
            ],
            'migration' => [
                'active' => 0,
            ],
            'activer_user_action'      => 1,
            'offline_time_start'       => null,
            'offline_time_end'         => null,
            'app_master_key_filepath'  => '',
            'app_public_key_filepath'  => '',
            'app_private_key_filepath' => '',
            'admin' => [
                'ProSanteConnect' => [
                    'enable_psc_authentication' => '0',
                    'enable_login_button'       => '0',
                ]
            ],
            'mb_oid' => '1.2.25.1.30.1234',
        ]);

        return $this;
    }

    /**
     * legacy convert key
     * @return self
     */
    private function convertConfigs(): self
    {
        // db
        $this->configs['db']['std']['dbhost'] = $this->configs['database_host'];
        unset($this->configs['database_host']);

        $this->configs['db']['std']['dbname'] = $this->configs['database_name'];
        unset($this->configs['database_name']);

        $this->configs['db']['std']['dbuser'] = $this->configs['database_user'];
        unset($this->configs['database_user']);

        $this->configs['db']['std']['dbpass'] = $this->configs['database_pass'];
        unset($this->configs['database_pass']);

        // elasticsearch
        unset($this->configs['elastic_host']);
        unset($this->configs['elastic_port']);

        // mutex
        $this->configs['mutex_drivers']['CMbRedisMutex'] = $this->configs['mutex_driver_redis'] ? 1 : 0;
        unset($this->configs['mutex_driver_redis']);

        $this->configs['mutex_drivers']['CMbAPCMutex'] = $this->configs['mutex_driver_apc'] ? 1 : 0;
        unset($this->configs['mutex_driver_apc']);

        $this->configs['mutex_drivers']['CMbFileMutex'] = $this->configs['mutex_driver_files'] ? 1 : 0;
        unset($this->configs['mutex_driver_files']);

        $this->configs['mutex_drivers_params']['CMbRedisMutex'] = $this->configs['mutex_redis_driver_params'];
        unset($this->configs['mutex_redis_driver_params']);

        return $this;
    }

    /**
     * @return bool|null
     * @throws Exception
     */
    private function storeConfigs(): ?bool
    {
        return $this->mbConfig->update($this->configs, false);
    }

    /**
     * @return mixed
     */
    private function askQuestions()
    {
        // general
        $this->ask('product_name', new Question('Please enter the product name: ', 'Hospilibre'));
        $this->ask('company_name', new Question('Enter the company name: ', 'OpenXtrem'));
        $this->ask('page_title', new Question('Enter the page title: ', 'Hospilibre SIH'));
        $this->ask('root_dir', new Question('Enter the root directory: ', $this->path));
        $this->ask('base_url', new Question('Enter the base url: ', 'http://httpd'));
        $this->ask('external_url', new Question('Enter the external url: ', 'http://localhost'));
        $this->ask('instance_role', new ChoiceQuestion('Select instance role: ', ['qualif', 'prod'], 'qualif'));

        // database
        $this->ask('database_host', new Question('Enter the database host: ', $this->db_host ?? 'db'));
        $this->ask('database_name', new Question('Enter the database name: ', $this->db_name ?? 'hospilibre'));
        $this->ask('database_user', new Question('Enter the database user: ', $this->db_user ?? 'dev'));
        $this->ask('database_pass', new Question('Enter the database password: ', $this->db_pass ?? 'oxdev17!'), true);

        // elastic search
        $this->ask('elastic_host', new Question('Enter the elastic search host: ', $this->es_host ?? 'es01'));
        $this->ask('elastic_port', new Question('Enter the elastic search port: ', $this->es_port ?? '9200'));

        // memory
        $this->ask(
            'shared_memory',
            new ChoiceQuestion(
                'Select local shared memory: ',
                ['disk', 'apcu'],
                'apcu'
            )
        );
        $this->ask(
            'shared_memory_distributed',
            new ChoiceQuestion(
                'Select distributed shared memory: ',
                ['disk', 'redis'],
                'redis'
            )
        );
        $this->ask(
            'shared_memory_params',
            new Question('Enter the redis params (ex: 127.0.0.1:6379, redis:6379): ', $this->ci_mode ? '127.0.0.1:6379' : 'redis:6379'),
            false,
            $this->configs['shared_memory_distributed'] === 'redis',
        );

        // session
        $this->ask(
            'session_handler',
            new ChoiceQuestion(
                'Select session handler: ',
                ['files', 'redis', 'mysql'],
                $this->ci_mode ? 'files' : 'redis'
            )
        );
        $this->ask(
            'session_handler_mutex_type',
            new ChoiceQuestion('Select mutex session mysql: ', ['mysql', 'files', 'system'], 'mysql'),
            false,
            $this->configs['session_handler'] === 'mysql'
        );

        // mutex
        $this->ask(
            'mutex_driver_files',
            new ConfirmationQuestion('Enable mutex driver files [y/N] ?', $this->ci_mode)
        );
        $this->ask(
            'mutex_driver_apc',
            new ConfirmationQuestion('Enable mutex driver apc [y/N] ?', $this->ci_mode)
        );
        $this->ask(
            'mutex_driver_redis',
            new ConfirmationQuestion('Enable mutex driver redis [Y/n] ?', !$this->ci_mode)
        );

        $this->ask(
            'mutex_redis_driver_params',
            new Question('Enter the redis params (ex: redis:6379): ', 'redis:6379'),
            false,
            $this->configs['mutex_driver_redis'] === true,
        );

        if ($this->ci_mode) {
            return true;
        }

        /* Display configurations before confirming */
        $configs_resume = [];
        foreach ($this->configs as $key => $value) {
            if ($key === 'database_pass') {
                $value = str_repeat('*', strlen($value));
            }
            $configs_resume[] = [$key, $value];
        }

        $this->io->createTable()
            ->setHeaderTitle('Configurations')
            ->setHeaders(['config', 'value'])
            ->setRows($configs_resume)
            ->render();

        return $this->question_helper->ask(
            $this->input,
            $this->output,
            new ConfirmationQuestion('Do you confirm this settings [Y/n] ?', true)
        );
    }

    /**
     * @param string   $name     The configuration name
     * @param Question $question
     * @param bool     $hidden
     * @param bool     $condition
     *
     * @return mixed
     */
    private function ask(string $name, Question $question, bool $hidden = false, bool $condition = true)
    {
        if (!$condition || $this->ci_mode) {
            return $this->configs[$name] = $question->getDefault();
        }

        if ($hidden && !(defined('PHPUNIT_HOSPILIBRE_TESTSUITE') && PHPUNIT_HOSPILIBRE_TESTSUITE)) {
            $question->setHidden(true);
        }

        return $this->configs[$name] = $this->question_helper->ask($this->input, $this->output, $question);
    }

    /**
     * @return array
     */
    public function getConfigs(): array
    {
        return $this->configs;
    }

    private function getExternalDatabaseName(CExternalDataSourceImport $import): string
    {
        $name = $import->getSourceNameForSQL();
        if (
            $this->ci_mode &&
            !empty($this->pipeline_id) &&
            in_array($import->getSourceName(), $this->pipeline_externals)
        ) {
            $name = 'pipeline_' . $this->pipeline_id . '_' . $name;
        }
        return $name;
    }
}
