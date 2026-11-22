<?php
/**
 * @package Hospilibre\Developpement
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Developpement;

use Ox\Core\CAppUI;

class CHospilibreConfiguration extends CDashboardConfiguration {
  static $specificVariables = array(
    'object_handlers',
    'index_handlers',
    'eai_handlers',
  );

  static $hospilibreVariables = array(
    'root_dir',
    'master_key_filepath',
    'db std dbhost',
    'offline',
    'offline_non_admin',
    'instance_role',
    'mb_id',
    'mb_oid',
    'servers_ip',
    'minify_javascript',
    'minify_css',
    'ref_pays',
    'hide_confidential',
    'readonly',
    'log_datasource_metrics',
    'log_access',
    'access_log_buffer_lifetime',
    'human_long_request_level',
    'bot_long_request_level',
    'shared_memory',
    'shared_memory_distributed',
    'shared_memory_params',
    'session_handler',
    'session_handler_mutex_type',
    'mutex_drivers CMbRedisMutex',
    'mutex_drivers CMbAPCMutex',
    'mutex_drivers CMbFileMutex',
    'mutex_drivers_params CMbRedisMutex',
    'mutex_drivers_params CMbFileMutex',
    'monitorClient redis_idle_threshold',
    'offline_time_start',
    'offline_time_end',
    'purify_text_input',
    'config_db',
    'dataminer_limit',
    'enslaving_active',
    'enslaving_ratio',
  );

  /**
   * @see parent::init()
   */
  public function init() {
    $this->configuration["mbConfig"] = array();

    foreach (self::$hospilibreVariables as $_hospilibre_variable) {
      $variable_value = CAppUI::conf($_hospilibre_variable);

      $configurationVariable = new CSimpleConfigurationVariable($_hospilibre_variable);
      $configurationVariable->exists = true;
      if ($variable_value) {
        $configurationVariable->setValue($variable_value);
      }

      $this->configuration["mbConfig"][] = $configurationVariable;
    }

    foreach (self::$specificVariables as $_hospilibre_variable) {
      $handler      = CAppUI::conf($_hospilibre_variable);

      foreach ($handler as $key => $_handler_parameter) {
        $tokenized_path = $_hospilibre_variable . " " . $key;

        $hospilibreParameter = new CSimpleConfigurationVariable($tokenized_path, $_handler_parameter);
        $hospilibreParameter->exists = true;
        $this->configuration["mbConfig"][] = $hospilibreParameter;
      }
    }
  }

  /**
   * Import a set of data inside $this->configuration variable
   *
   * @param array $jsonData Must be the root of the data ($
   *
   * @return CHospilibreConfiguration
   */
  public static function fromJson($jsonData) {
    $hospilibre_configuration = new CHospilibreConfiguration();

    $imported_config = $jsonData["configuration"]["mbConfig"];

    foreach ($imported_config as $_json_config) {
      $hospilibreSetting = new CSimpleConfigurationVariable($_json_config["varName"], $_json_config["value"]);
      $hospilibreSetting->exists = $_json_config["exists"];

      $hospilibre_configuration->configuration["mbConfig"][] = $hospilibreSetting;
    }

    return $hospilibre_configuration;
  }
}