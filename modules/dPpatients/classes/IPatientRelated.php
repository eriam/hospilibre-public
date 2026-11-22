<?php
/**
 * @package Hospilibre\Patients
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Patients;

/**
 * Patient Related interface, can be used on any class linked to a patient
 */
interface IPatientRelated {
  /**
   * Loads the related patient, wether it is a far or a close reference
   *
   * @return CPatient
   */
  function loadRelPatient();
}
