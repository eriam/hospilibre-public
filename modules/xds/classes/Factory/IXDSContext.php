<?php
/**
 * @package Hospilibre\Xds
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Réimplémentation GPL (clean-room, reconstruite depuis l'unique appelant
 * CXDSFactory et l'implémenteur CCDAFactory, sans copier la source OXOL).
 */

namespace Ox\Interop\Xds\Factory;

/**
 * Contrat d'un objet de contexte XDS : il sait initialiser une fabrique XDS
 * à partir de ses propres données (patient, objet cible, etc.).
 */
interface IXDSContext
{
    /**
     * Initialise la fabrique XDS fournie à partir du contexte courant.
     *
     * @param CXDSFactory $xds Fabrique XDS à alimenter
     *
     * @return void
     */
    public function initializeXDS(CXDSFactory $xds): void;
}
