<?php
/**
 * @package Hospilibre\Maternite
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Maternite\Timeline\Category;

use Hospilibre\Maternite\Timeline\AlsoPregnancyInCategory;
use Hospilibre\System\Timeline\ITimelineCategory;
use Hospilibre\System\Timeline\TimelineCategory;

/**
 * Class StayCategoryMaternite
 */
class StayCategoryMaternite extends TimelineCategory implements ITimelineCategory {
  use AlsoPregnancyInCategory;

  /**
   * @inheritDoc
   */
  public function getEventsByDate(): array {
    $stays = $this->pregnancy->loadRefsSejours();

    foreach ($stays as $_stay) {
      $_stay->loadRefPraticien();

      if ($this->selectedPractitioner($_stay->_ref_praticien)) {
        $dates_list = $this->makeListDates($_stay->entree);
        $this->appendTimeline($dates_list[0], $dates_list[1], $dates_list[2], 'stays', $_stay);
      }
    }

    return $this->getTimeline();
  }
}