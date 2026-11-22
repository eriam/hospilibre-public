<?php
/**
 * @package Hospilibre\Reservation
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

// Préférences par Module
use Hospilibre\System\CPreferences;

CPreferences::$modules["reservation"] = array(
  "planning_resa_height",
);
