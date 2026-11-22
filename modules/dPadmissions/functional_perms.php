<?php
/**
 * @package Hospilibre\Admissions
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

// Permissions fonctionnels par Module
use Hospilibre\System\CPreferences;

CPreferences::$modules['dPadmissions'] = array (
  'show_dh_admissions'
);