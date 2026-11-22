<?php
/**
 * @package Hospilibre\Cabinet
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Cabinet\Timeline\Category;


use Exception;
use Ox\Core\CStoredObject;
use Hospilibre\System\Timeline\ITimelineCategory;
use Hospilibre\System\Timeline\TimelineCategory;

/**
 * Class BirthCategoryConsultation
 */
class BirthCategoryConsultation extends TimelineCategory implements ITimelineCategory {
  /**
   * @inheritDoc
   * @throws Exception
   */
  public function getEventsByDate(): array {
    if ($this->selectedPractitioner(null)) {
      [$year, $month, $day] = $this->makeListDates($this->patient->naissance);
      $this->appendTimeline($year, $month, $day, "birth", $this->patient);
    }

    return $this->getTimeline();
  }
}