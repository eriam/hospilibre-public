<?php
/**
 * @package Hospilibre\Soins
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Soins\Timeline\Category;

use Ox\Core\CMbObject;
use Hospilibre\Soins\Timeline\AlsoStayInCategory;
use Hospilibre\System\Timeline\ITimelineCategory;
use Hospilibre\System\Timeline\TimelineCategory;

/**
 * Class AnesthVisitCategorySoins
 */
class AnesthVisitCategorySoins extends TimelineCategory implements ITimelineCategory {
  use AlsoStayInCategory;

  /**
   * @inheritDoc
   */
  public function getEventsByDate(): array {
    // Anesth visits
    $this->stay->loadRefsOperations();
    foreach ($this->stay->_ref_operations as $_surgery) {
      if ($_surgery->date_visite_anesth) {
        $_surgery->loadRefVisiteAnesth();
        $datetime = "{$_surgery->date_visite_anesth} {$_surgery->time_visite_anesth}";

        if ($this->selectedPractitioner($_surgery->_ref_anesth_visite)) {
          $dates_list = $this->makeListDates($datetime);
          $this->appendTimeline($dates_list[0], $dates_list[1], $dates_list[2], 'anesth_visits', $_surgery);

          $this->addToInvolvedUser($_surgery->_ref_anesth_visite);
        }
      }
    }
    return $this->getTimeline();
  }
}