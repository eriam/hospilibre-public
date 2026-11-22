<?php
/**
 * @package Hospilibre\Messagerie
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CCanDo;
use Ox\Core\CView;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Messagerie\CUserMessageDest;

CCanDo::checkRead();

CView::checkin();

$user = CMediusers::get();

$counts = array(
  array('name' => 'inbox', 'count' =>  CUserMessageDest::countUnreadFor($user)),
  array('name' => 'archive', 'count' =>  CUserMessageDest::countArchivedFor($user)),
  array('name' => 'sentbox', 'count' =>  CUserMessageDest::countSentFor($user)),
  array('name' => 'draft', 'count' =>  CUserMessageDest::countDraftedFor($user))
);

echo json_encode($counts);