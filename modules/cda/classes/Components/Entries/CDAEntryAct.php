<?php

/**
 * @package Ox\Interop\Cda\Components\Entries
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Cda\Components\Entries;

use Ox\Interop\Cda\CCDAClasseBase;
use Ox\Interop\Cda\Rim\CCDARIMAct;
use Ox\Interop\Cda\Rim\CCDARIMRole;

/**
 * Base commune des entrées CDA de type « acte » (RIM Act).
 *
 * Chaque sous-classe complète les différentes sections de l'acte
 * (code, texte, titre, statut) via les points d'extension protégés.
 *
 * @package Ox\Interop\Cda\Components\Entries
 */
abstract class CDAEntryAct extends CDAEntry
{
    /** @var CCDARIMAct */
    protected $entry_content;

    /**
     * Assemble l'acte CDA puis renseigne successivement chacune de ses parties.
     *
     * @return CCDARIMAct
     */
    public function build(): CCDAClasseBase
    {
        /** @var CCDARIMAct $act */
        $act = parent::build();

        $this->setCode($act);
        $this->setText($act);
        $this->setTitle($act);
        $this->setStatusCode($act);

        return $act;
    }

    /**
     * Renseigne le code de l'acte. À surcharger dans les sous-classes.
     *
     * @param CCDARIMRole $entry_content
     */
    protected function setCode(CCDARIMAct $entry_content): void
    {
        // point d'extension : surcharge dans les sous-classes
    }

    /**
     * Renseigne le titre de l'acte. À surcharger dans les sous-classes.
     *
     * @param CCDARIMRole $entry_content
     */
    protected function setTitle(CCDARIMAct $entry_content): void
    {
        // point d'extension : surcharge dans les sous-classes
    }

    /**
     * Renseigne le code de statut de l'acte. À surcharger dans les sous-classes.
     *
     * @param CCDARIMRole $entry_content
     */
    protected function setStatusCode(CCDARIMAct $entry_content): void
    {
        // point d'extension : surcharge dans les sous-classes
    }

    /**
     * Renseigne le texte de l'acte. À surcharger dans les sous-classes.
     *
     * @param CCDARIMRole $entry_content
     */
    protected function setText(CCDARIMAct $entry_content): void
    {
        // point d'extension : surcharge dans les sous-classes
    }
}
