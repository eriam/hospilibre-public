<?php
/**
 * @package Hospilibre\System
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CApp;
use Ox\Core\CCanDo;
use Ox\Core\Sessions\CSessionHandler;
use Hospilibre\System\Cron\CCronJob;
use Hospilibre\System\Cron\CCronJobManager;

CCanDo::checkAdmin();

CSessionHandler::writeClose();

$cronjob         = new CCronJob();
$cronjob->active = '1';

try {
  $jobs = $cronjob->loadMatchingList();
}
catch (Exception $e) {
  $jobs = [];
}

if (!$jobs) {
  CApp::rip();
}

$manager = new CCronJobManager();

foreach ($jobs as $_job) {
  $manager->registerJob($_job);
}

$manager->runJobs();
