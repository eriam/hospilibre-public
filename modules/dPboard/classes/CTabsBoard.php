<?php
/**
 * @package Hospilibre\Board
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */
namespace Hospilibre\Board;
use Ox\Core\Module\CModule;

use Ox\Core\Module\AbstractTabsRegister;
use Hospilibre\Mediusers\CMediusers;

/**
 * @codeCoverageIgnore
 */
class CTabsBoard extends AbstractTabsRegister
{
    public function registerAll(): void
    {
        $this->registerFile("vw_month", TAB_READ);
        $this->registerFile("vw_week", TAB_READ);
        $this->registerFile("vw_day", TAB_READ);
        $this->registerFile("vw_idx_sejour", TAB_READ);

        if (CModule::getActive("dPprescription")) {
            $this->registerFile("vw_bilan_prescription", TAB_READ);
            $this->registerFile("vw_bilan_transmissions", TAB_READ);
        }


        $this->registerFile("vw_bilan_actes_realises", TAB_READ);
        $this->registerFile("vw_interv_non_cotees", TAB_EDIT);

        if (CModule::getActive("search")) {
            $this->registerFile("vw_search", TAB_READ);
        }

        $this->registerFile("vw_stats", TAB_READ);
        $this->registerFile("vw_exams_comp", TAB_READ);

        if (CMediusers::get()->isSecretaire()) {
            $this->registerFile("tdbSecretaire", TAB_READ);
        }
    }
}


