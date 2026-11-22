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
 * Class ExpectedTermCategoryMaternite
 */
class ExpectedTermCategoryMaternite extends TimelineCategory implements ITimelineCategory {
  use AlsoPregnancyInCategory;

  /**
   * @inheritDoc
   */
  public function getEventsByDate(): array {
    if ($this->selectedPractitioner(null)) {
      $expected_term = $this->pregnancy->terme_prevu;
      if ($expected_term) {
        $dates_list = $this->makeListDates($expected_term);
        $this->appendTimeline($dates_list[0], $dates_list[1], $dates_list[2], 'expected_term', $this->pregnancy);
      }
    }

    return $this->getTimeline();
  }
}