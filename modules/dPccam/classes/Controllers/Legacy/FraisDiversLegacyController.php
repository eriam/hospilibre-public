<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\Ccam\Controllers\Legacy;

use Ox\Core\CLegacyController;
use Ox\Core\CMbObject;
use Ox\Core\CView;
use Hospilibre\Admin\CAccessMedicalData;
use Hospilibre\Ccam\CCodable;
use Hospilibre\Ccam\CFraisDivers;
use Hospilibre\Ccam\CFraisDiversType;

class FraisDiversLegacyController extends CLegacyController
{
    /**
     * Affiche l'index des types de frais divers, en pré-sélectionnant
     * éventuellement un type transmis en paramètre.
     */
    public function viewFraisDiversTypes(): void
    {
        $this->checkPermAdmin();

        $selectedTypeId = CView::get('frais_divers_type_id', 'ref class|CFraisDiversType', true);
        CView::checkin();

        $selectedType = new CFraisDiversType();
        $selectedType->load($selectedTypeId);

        $allTypes = $selectedType->loadList(null, 'code');

        $this->renderSmarty('vw_idx_frais_divers_types.tpl', [
            'type'       => $selectedType,
            'list_types' => $allTypes,
        ]);
    }

    /**
     * Reconstruit le formulaire d'ajout de frais divers pour un objet codable
     * donné, en initialisant les valeurs par défaut.
     */
    public function refreshFraisDivers(): void
    {
        $this->checkPermEdit();

        $objectGuid = CView::get('object_guid', 'str');
        CView::checkin();

        /** @var CCodable $codable */
        $codable = CMbObject::loadFromGuid($objectGuid);

        CAccessMedicalData::logAccess($codable);

        $codable->loadRefsFraisDivers();

        $newFrais = new CFraisDivers();
        $newFrais->setObject($codable);
        $newFrais->quantite    = 1;
        $newFrais->coefficient = 1;
        $newFrais->num_facture = 1;

        // Sur une consultation validée, la nouvelle facture prend le rang suivant.
        if ($codable->_class === 'CConsultation' && $codable->valide) {
            $codable->loadRefFacture();
            $newFrais->num_facture = count($codable->_ref_factures) + 1;
        }

        $newFrais->loadListExecutants();
        $newFrais->loadExecution();

        $this->renderSmarty('inc_form_add_frais_divers.tpl', [
            'object'       => $codable,
            'frais_divers' => $newFrais,
        ]);
    }
}
