<?php
/**
 * @package Hospilibre\Search
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Search;

use Hospilibre\Patients\CPatient;
use Hospilibre\Mediusers\CMediusers;

/**
 * Search Related interface, can be used on any class linked to a search
 */
interface IIndexableObject {

  /**
   * Get the patient of CMbobject
   *
   * @return CPatient
   */
  function getIndexablePatient();

  /**
   * Get the praticien_id of CMbobject
   *
   * @return CMediusers
   */
  function getIndexablePraticien();

  /**
   * Loads the related fields for indexing datum
   *
   * @return array
   */
  function getIndexableData();

  /**
   * Redesign the content of the body you will index
   *
   * @param string $content The content you want to redesign
   *
   * @return string
   */
  function getIndexableBody($content);
}

