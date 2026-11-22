<?php
/**
 * @package Hospilibre\Maternite
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Maternite\Timeline\Category;

use Ox\Core\CMbObject;
use Hospilibre\Maternite\Timeline\AlsoPregnancyInCategory;
use Hospilibre\System\Timeline\ITimelineCategory;
use Hospilibre\System\Timeline\TimelineCategory;

/**
 * Class AppointmentAnesthCategoryMaternite
 */
class AppointmentAnesthCategoryMaternite extends TimelineCategory implements ITimelineCategory {
  use AlsoPregnancyInCategory;

  /**
   * @inheritDoc
   */
  public function getEventsByDate(): array {
    $this->pregnancy->loadRefsConsultations(true);

    foreach ($this->pregnancy->_ref_consultations_anesth as $_anesth_appointment) {
      $appointment = $_anesth_appointment->loadRefConsultation();
      $appointment->loadRefPraticien();
      $appointment->countDocItems();

      if ($this->selectedPractitioner($appointment->_ref_chir)) {
        $dates_list = $this->makeListDates($appointment->_datetime);

        $this->appendTimeline($dates_list[0], $dates_list[1], $dates_list[2], 'anesth_appointments', $_anesth_appointment);
        $this->addToInvolvedUser($appointment->_ref_chir);
      }
    }

    return $this->getTimeline();
  }
}