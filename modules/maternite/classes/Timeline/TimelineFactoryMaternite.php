<?php
/**
 * @package Hospilibre\Maternite
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Maternite\Timeline;

use Ox\Core\Autoload\IShortNameAutoloadable;
use Hospilibre\Patients\CPatient;
use Hospilibre\PlanningOp\CSejour;
use Hospilibre\System\Timeline\ITimelineCategory;

/**
 * Class TimelineFactoryMaternite
 */
abstract class TimelineFactoryMaternite implements IShortNameAutoloadable {
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
    $category->setPatient(TimelineFactoryMaternite::$patient);
    $category->setUsers($users);
    $category->setPregnancy(TimelineFactoryMaternite::$pregnancy);

    return $category;
  }
}
