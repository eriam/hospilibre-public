<?php
/**
 * @package Hospilibre\Messagerie
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

use Ox\Core\CCanDo;
use Ox\Core\CView;
use Hospilibre\Messagerie\CUserMessageDestGroupController;

CCanDo::checkAdmin();

(new CUserMessageDestGroupController())->doIt();
