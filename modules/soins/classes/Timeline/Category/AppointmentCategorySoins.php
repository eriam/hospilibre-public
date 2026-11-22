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
 * Class AppointmentCategorySoins
 */
class AppointmentCategorySoins extends TimelineCategory implements ITimelineCategory {
  use AlsoStayInCategory;

  /**
   * @inheritDoc
   */
  public function getEventsByDate(): array {
    $this->stay->loadRefsConsultations();
    $appointments = CMbObject::massLoadFwdRef($this->stay->_ref_consultations, 'plageconsult_id');
    $users        = CMbObject::massLoadFwdRef($appointments, 'chir_id');

    CMbObject::massLoadFwdRef($users, 'function_id');
    CMbObject::massLoadFwdRef($this->stay->_ref_consultations, 'categorie_id');

    foreach ($this->stay->_ref_consultations as $_appointment) {
      $_appointment->loadRefPraticien();
      $_appointment->loadRefCategorie();
      $_appointment->countDocItems();

      if ($this->selectedPractitioner($_appointment->_ref_chir)) {
        $dates_list = $this->makeListDates($_appointment->_datetime);
        $this->appendTimeline($dates_list[0], $dates_list[1], $dates_list[2], 'appointments', $_appointment);

        $this->addToInvolvedUser($_appointment->_ref_chir);
      }
    }

    return $this->getTimeline();
  }
}