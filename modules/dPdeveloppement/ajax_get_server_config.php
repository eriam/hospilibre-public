<?php
/**
 * @package Hospilibre\Developpement
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CCanDo;
use Hospilibre\Developpement\CApacheConfiguration;
use Hospilibre\Developpement\CHospilibreConfiguration;
use Hospilibre\Developpement\CMySQLConfiguration;
use Hospilibre\Developpement\CPhpConfiguration;

CCanDo::checkRead();

$phpConfigurationInstance = new CPhpConfiguration();
$mysqlConfigurationInstance = new CMySQLConfiguration();
$apacheConfigurationInstance = new CApacheConfiguration();
$hospilibreConfigurationInstance = new CHospilibreConfiguration();

$mysqlConfigurationInstance->init();
$apacheConfigurationInstance->init();
$phpConfigurationInstance->init();
$hospilibreConfigurationInstance->init();
/*$phpConfiguration = $phpConfigurationInstance->serialize();
$mysqlConfiguration = $mysqlConfigurationInstance->serialize();
$apacheConfiguration = $apacheConfigurationInstance->serialize();*/

$returnArray = array(
  "apache" => $apacheConfigurationInstance,
  "php" => $phpConfigurationInstance,
  "mysql" => $mysqlConfigurationInstance,
  "hospilibre" => $hospilibreConfigurationInstance
);

echo json_encode($returnArray);
