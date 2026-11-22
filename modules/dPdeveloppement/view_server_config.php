<?php
/**
 * @package Hospilibre\Developpement
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CApp;
use Ox\Core\CAppUI;
use Ox\Core\CCanDo;
use Ox\Core\CSmartyDP;
use Hospilibre\Developpement\CApacheConfiguration;
use Hospilibre\Developpement\CHospilibreConfiguration;
use Hospilibre\Developpement\CMySQLConfiguration;
use Hospilibre\Developpement\CPhpConfiguration;

CCanDo::checkRead();

$servers_ip = CAppUI::conf("servers_ip");

if ($servers_ip) {
  $serversConfiguration = array();
  $serversList          = array();
  //Building URL
  $get        = array();
  $get["m"]   = "dPdeveloppement";
  $get["raw"] = "ajax_get_server_config";
  $result     = CApp::multipleServerCall($servers_ip, $get, null);

  //Import the values for every server
  foreach ($result as $key => $server) {
    $serversList[] = $key;
    //Convert json string to PHP array
    $arrayResult = json_decode($server["body"], true);


    $apacheConfigurationInstance = CApacheConfiguration::fromJson($arrayResult["apache"]);
    $phpConfigurationInstance    = CPhpConfiguration::fromJson($arrayResult["php"]);
    $mysqlConfigurationInstance  = CMySQLConfiguration::fromJson($arrayResult["mysql"]);
    $hospilibreConfigurationInstance = CHospilibreConfiguration::fromJson($arrayResult["hospilibre"]);

    $serversConfiguration[$key] = array(
      "apacheConfiguration" => $apacheConfigurationInstance->configuration,
      "phpConfiguration"    => $phpConfigurationInstance->configuration,
      "mysqlConfiguration"  => $mysqlConfigurationInstance->configuration,
      "hospilibreConfiguration" => $hospilibreConfigurationInstance->configuration
    );
  }

  $smarty = new CSmartyDP();

  $smarty->assign("serversConfiguration", $serversConfiguration);
  $smarty->assign("serversList", $serversList);
  $smarty->assign("firstLine", $serversList[0]);

  $smarty->display("view_multiple_server_config.tpl");
}
else {
  $phpConfigurationInstance    = new CPhpConfiguration;
  $mysqlConfigurationInstance  = new CMySQLConfiguration;
  $apacheConfigurationInstance = new CApacheConfiguration;
  $hospilibreConfigurationInstance = new CHospilibreConfiguration;

  $phpConfigurationInstance->init();
  $mysqlConfigurationInstance->init();
  $apacheConfigurationInstance->init();
  $hospilibreConfigurationInstance->init();

  $phpConfiguration    = $phpConfigurationInstance->configuration;
  $mysqlConfiguration  = $mysqlConfigurationInstance->configuration;
  $apacheConfiguration = $apacheConfigurationInstance->configuration;

  $smarty = new CSmartyDP();


  $smarty->assign("nbTabs", 4);
  $smarty->assign("phpConfiguration", $phpConfiguration);
  $smarty->assign("mysqlConfiguration", $mysqlConfiguration);
  $smarty->assign("apacheConfiguration", $apacheConfiguration);
  $smarty->assign("hospilibreConfiguration", $hospilibreConfigurationInstance->configuration);
  $smarty->display("view_server_config.tpl");
}