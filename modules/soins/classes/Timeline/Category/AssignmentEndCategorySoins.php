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
 * Class AssignmentEndCategorySoins
 */
class AssignmentEndCategorySoins extends TimelineCategory implements ITimelineCategory {
  use AlsoStayInCategory;

  /**
   * @inheritDoc
   */
  public function getEventsByDate(): array {
    if ($this->selectedPractitioner(null)) {
      $this->stay->loadRefsAffectations();
      CMbObject::massLoadFwdRef($this->stay->_ref_affectations, 'service_id');
      CMbObject::massLoadFwdRef($this->stay->_ref_affectations, 'lit_id');
      foreach ($this->stay->_ref_affectations as $_assignment) {
        $_assignment->loadRefLit();
        $_assignment->loadRefService();
        $_assignment->updateFormFields();

        $dates_list = $this->makeListDates($_assignment->sortie);
        $this->appendTimeline($dates_list[0], $dates_list[1], $dates_list[2], 'assignment_end', $_assignment);
      }
    }

    return $this->getTimeline();
  }
}