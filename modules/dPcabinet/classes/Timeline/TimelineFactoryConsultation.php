<?php
/**
 * @package Hospilibre\Cabinet
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Cabinet\Timeline;

use Ox\Core\Autoload\IShortNameAutoloadable;
use Hospilibre\Patients\CPatient;
use Hospilibre\PlanningOp\CSejour;
use Hospilibre\System\Timeline\ITimelineCategory;

/**
 * Class TimelineFactoryConsultation
 */
abstract class TimelineFactoryConsultation implements IShortNameAutoloadable {
  /** @var CPatient - patient involved */
  public static $patient;
  /** @var CSejour */
  public static $pregnancy;

  /**
   * Hydrates a timeline category
   *
   * @param ITimelineCategory $category
   * @param array             $users - practitioners filter (e.g. only Dr Thingy)
   *
   * @return ITimelineCategory
   */
  public static function makeCategory(ITimelineCategory $category, array $users) {
    $category->setPatient(TimelineFactoryConsultation::$patient);
    $category->setUsers($users);

    return $category;
  }
}
