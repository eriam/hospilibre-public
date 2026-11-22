<?php

/**
 * @package Hospilibre\soins
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\Soins\Controllers\Legacy;

use Exception;
use Hospilibre\PlanningOp\CSejour;
use Ox\Core\CLegacyController;
use Ox\Core\CView;

class SejourFormController extends CLegacyController
{
    /**
     * Affiche les formulaires rattachés à un séjour et à son patient.
     *
     * @throws Exception
     */
    public function displayFormsWithContext(): void
    {
        $this->checkPermRead();

        // Récupération de l'identifiant du séjour depuis la requête.
        $stayId = CView::get('sejour_id', 'ref class|CSejour');
        CView::checkin();

        // Chargement du séjour ciblé, puis de son patient associé.
        $stay = new CSejour();
        $stay->load($stayId);
        $relatedPatient = $stay->loadRefPatient();

        $this->renderSmarty(
            'vw_sejour_forms.tpl',
            [
                'sejour'  => $stay,
                'patient' => $relatedPatient,
            ]
        );
    }
}
