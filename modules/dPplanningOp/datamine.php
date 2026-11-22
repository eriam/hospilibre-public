<?php
/**
 * @package Hospilibre\PlanningOp
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CCanDo;
use Ox\Core\CView;
use Hospilibre\PlanningOp\COperationMiner;
use Hospilibre\System\CDataMinerWorker;

CCanDo::checkEdit();

CView::checkin();

CDataMinerWorker::mine(COperationMiner::class);