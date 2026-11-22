<?php
/**
 * @package Hospilibre\System
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\System\Timeline;

use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Patients\CPatient;

/**
 * Interface of a category of a timeline
 */
interface ITimelineCategory {
  /**
   * @param CPatient $patient
   *
   * @return void
   */
  public function setPatient(CPatient $patient): void;

  /**
   * @param CMediusers[] $users
   *
   * @return void
   */
  public function setUsers(array $users): void;

  /**
   * @return array
   */
  public function getEventsByDate(): array;

  /**
   * @return int
   */
  public function getAmountEvents(): int;

  /**
   * @return array
   */
  public function getInvolvedUsers(): array;
}
