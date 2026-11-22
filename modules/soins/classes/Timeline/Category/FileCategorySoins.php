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
 * Class FileCategorySoins
 */
class FileCategorySoins extends TimelineCategory implements ITimelineCategory {
  use AlsoStayInCategory;

  /**
   * @inheritDoc
   */
  public function getEventsByDate(): array {
    $this->stay->loadRefsFiles();

    foreach ($this->stay->_ref_files as $_file) {
      $_file->loadRefAuthor();
      $_file->loadTargetObject();

      if ($this->selectedPractitioner($_file->_ref_author)) {
        $this->addToInvolvedUser($_file->_ref_author);

        $dates_list = $this->makeListDates($_file->file_date);
        $this->appendTimeline($dates_list[0], $dates_list[1], $dates_list[2], 'files', $_file);
      }
    }

    return $this->getTimeline();
  }
}