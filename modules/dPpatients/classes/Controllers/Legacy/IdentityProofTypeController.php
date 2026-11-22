<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\Patients\Controllers\Legacy;

use Ox\Core\CLegacyController;
use Ox\Core\CMbException;
use Ox\Core\CView;
use Hospilibre\Patients\CIdentityProofType;

class IdentityProofTypeController extends CLegacyController
{
    /**
     * Ecran principal : liste des types de justificatifs actifs.
     */
    public function indexIdentityProofTypes(): void
    {
        $this->checkPermAdmin();
        CView::checkin();

        // Filtre pre-rempli sur les entrees actives.
        $default_filter          = new CIdentityProofType();
        $default_filter->active  = '1';

        $this->renderSmarty('identity_proof_type/index', [
            'filter' => $default_filter,
            'types'  => CIdentityProofType::getActiveTypes(),
            'total'  => CIdentityProofType::count(),
            'page'   => 0,
        ]);
    }

    /**
     * Recherche filtree (code, libelle, niveau de confiance, statut actif).
     */
    public function filterIdentityProofTypes(): void
    {
        $this->checkPermAdmin();

        $wanted_code   = CView::post('code', 'str');
        $wanted_label  = CView::post('label', 'str');
        $trust         = CView::post('trust_level', 'enum list|1|2|3');
        $only_active   = CView::post('active', 'bool');
        $current_page  = (int)CView::post('page', 'num default|0');

        CView::checkin();

        $matches = CIdentityProofType::filter($wanted_code, $wanted_label, $trust, $only_active, $current_page);

        $this->renderSmarty('identity_proof_type/list', [
            'types' => $matches,
            'total' => CIdentityProofType::count($wanted_code, $wanted_label, $trust),
            'page'  => $current_page,
        ]);
    }

    /**
     * Formulaire d'edition (creation si aucun identifiant fourni).
     *
     * @throws CMbException
     */
    public function editIdentityProofType(): void
    {
        $this->checkPermAdmin();

        $requested_id = CView::get('identity_proof_type_id', 'ref class|CIdentityProofType');

        CView::checkin();

        $edited_type = CIdentityProofType::findOrNew($requested_id);

        $this->renderSmarty('identity_proof_type/edit', ['type' => $edited_type]);
    }
}
