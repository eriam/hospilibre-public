<?php
/**
 * @package Hospilibre\Board
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Board;

use Ox\Core\CSetup;

/**
 * @codeCoverageIgnore
 */
class CSetupBoard extends CSetup {

  /**
   * Constructeur
   */
  function __construct() {
    parent::__construct();

    $this->mod_name = "dPboard";

    $this->makeRevision("0.0");

    $this->makeRevision("0.1");

    // user authorization to see others user in TDB
    $this->addFunctionalPermQuery("allow_other_users_board", 'write_right');

    $this->makeRevision("0.2");
    $this->setModuleCategory("circuit_patient", "metier");

    $this->makeRevision("0.3");
    $this->addPrefQuery("show_all_docs", 0);

    $this->makeRevision("0.31");

    $this->addPrefQuery("alternative display",0);

    $this->mod_version = "0.32";


  }
}
