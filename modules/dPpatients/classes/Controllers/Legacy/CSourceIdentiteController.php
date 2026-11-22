<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Patients\Controllers\Legacy;

use Ox\Core\CAppUI;
use Ox\Core\CLegacyController;
use Ox\Core\CRequest;
use Ox\Core\CSQLDataSource;
use Ox\Core\CView;
use Hospilibre\Files\CIdInterpreter;
use Hospilibre\Patients\CIdentityProofType;
use Hospilibre\Patients\CPatient;
use Hospilibre\Patients\CSourceIdentite;

/**
 * Description
 */
class CSourceIdentiteController extends CLegacyController
{
    public function correctSources(): void
    {
        $this->checkPermAdmin();

        $ds = CSQLDataSource::get('std');

        $request = new CRequest();
        $request->addSelect('GROUP_CONCAT(`source_identite_id`), `patient_id`');
        $request->addTable('`source_identite`');
        $request->addLJoin(
            [
                'files_hospilibre' =>
                    "`files_hospilibre`.`object_id` = `source_identite`.`source_identite_id`
                      AND `files_hospilibre`.`object_class` = 'CSourceIdentite'",
            ]
        );
        $request->addWhere(
            [
                'mode_obtention' => $ds->prepare('= ?', CSourceIdentite::MODE_OBTENTION_MANUEL),
                'file_id'        => "IS NULL",
            ]
        );
        $request->addGroup(
            '`patient_id`, `nom`, `nom_naissance`, `prenom_naissance`, `prenoms`, `prenom_usuel`, `date_naissance`'
        );
        $request->addHaving('COUNT(*) > 1');

        $results = $ds->loadHashList($request->makeSelect());

        if (!count($results)) {
            CAppUI::stepAjax(CAppUI::tr('CSourceIdentite-No source to delete'));

            return;
        }

        $patients = (new CPatient())->loadList(['patient_id' => CSQLDataSource::prepareIn($results)]);

        foreach ($results as $_source_identite_ids => $_patient_id) {
            $patient = $patients[$_patient_id];

            $_source_identite_ids = explode(',', $_source_identite_ids);

            $count_deleted = 0;

            foreach (
                (new CSourceIdentite())->loadList(
                    ['source_identite_id' => CSQLDataSource::prepareIn($_source_identite_ids)]
                ) as $_source_identite
            ) {
                if ($_source_identite->_id === $patient->source_identite_id) {
                    continue;
                }

                if ($msg = $_source_identite->delete()) {
                    CAppUI::stepAjax($msg, UI_MSG_ERROR);
                    continue;
                }

                $count_deleted++;
            }

            CAppUI::stepAjax($patient->_view . ' : ' . CAppUI::tr('CSourceIdentite-N sources deleted', $count_deleted));
        }
    }

    public function addJustificatif(): void
    {
        $this->checkPermEdit();

        $patient_id = CView::getRefCheckEdit('patient_id', 'ref class|CPatient');

        CView::checkin();

        $this->renderSmarty(
            'vw_add_justificatif',
            [
                'patient_id'           => $patient_id,
                'use_id_interpreter'   => CIdInterpreter::canBeUsed(),
                'identity_proof_types' => CIdentityProofType::getActiveTypes(),
            ]
        );
    }
}
