<?php
/**
 * @package Hospilibre\Soins
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Soins\Timeline\Category;

use Hospilibre\Soins\Timeline\AlsoStayInCategory;
use Hospilibre\System\Timeline\ITimelineCategory;
use Hospilibre\System\Timeline\TimelineCategory;

/**
 * Class TransmissionCategorySoins
 */
class TransmissionCategorySoins extends TimelineCategory implements ITimelineCategory {
  use AlsoStayInCategory;

  /**
   * @inheritDoc
   */
  public function getEventsByDate(): array {
    $this->stay->loadRefsTransmissions();
    foreach ($this->stay->_ref_transmissions as $_transmission) {
      $_transmission->loadRefUser();

      if ($this->selectedPractitioner($_transmission->_ref_user)) {
        $dates_list = $this->makeListDates($_transmission->date);
        $this->appendTimeline($dates_list[0], $dates_list[1], $dates_list[2], 'transmissions', $_transmission);
        $this->addToInvolvedUser($_transmission->_ref_user);
      }
    }

    return $this->getTimeline();
  }
}