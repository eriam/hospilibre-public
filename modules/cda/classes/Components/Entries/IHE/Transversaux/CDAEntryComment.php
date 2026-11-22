<?php

/**
 * @package Ox\Interop\Cda\Components\Entries\IHE\Transversaux
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Cda\Components\Entries\IHE\Transversaux;

use Ox\Interop\Cda\CCDAClasseCda;
use Ox\Interop\Cda\CCDAFactory;
use Ox\Interop\Cda\Components\Entries\CDAEntryAct;
use Ox\Interop\Cda\Rim\CCDARIMAct;
use Ox\Interop\Cda\Structure\CCDAPOCD_MT000040_Act;

/**
 * Entrée CDA representant un commentaire (profil IHE transversal).
 */
class CDAEntryComment extends CDAEntryAct
{
    /** @var string Identifiant de gabarit associe a cette entree */
    public const TEMPLATE_ID = '1.3.6.1.4.1.19376.1.5.3.1.4.2';

    /**
     * Initialise l'entree commentaire a partir de la fabrique CDA fournie.
     *
     * @param CCDAFactory $factory Fabrique de construction du document CDA
     */
    public function __construct(CCDAFactory $factory)
    {
        parent::__construct($factory);

        // Le contenu de l'entree repose sur un acte POCD_MT000040
        $this->entry_content = new CCDAPOCD_MT000040_Act();

        // Ajout de l'identifiant de gabarit complementaire attendu
        $this->addTemplateIds('2.16.840.1.113883.10.20.1.40');
    }

    /**
     * Construit le contenu de l'entree.
     *
     * Non implemente a ce niveau : les sous-classes fournissent leur logique.
     *
     * @param CCDARIMAct $entry_content Acte RIM a completer
     */
    protected function buildContent(CCDAClasseCda $entry_content): void
    {
        // Aucun traitement ici
    }
}
