<?php

/**
 * @package Hospilibre\Rpps
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Import\Rpps\Controllers\Legacy;

use Ox\Core\CApp;
use Ox\Core\CAppUI;
use Ox\Core\CLegacyController;
use Ox\Core\CLogger;
use Ox\Core\CSQLDataSource;
use Ox\Core\CView;
use Ox\Import\Rpps\CExternalMedecinBulkImport;
use Ox\Import\Rpps\CExternalMedecinSync;
use Ox\Import\Rpps\CMedecinExercicePlaceManager;
use Ox\Import\Rpps\CRppsFileDownloader;
use Ox\Import\Rpps\Entity\CDiplomeAutorisationExercice;
use Ox\Import\Rpps\Entity\CMssanteInfos;
use Ox\Import\Rpps\Entity\CPersonneExercice;
use Ox\Import\Rpps\Entity\CSavoirFaire;
use Hospilibre\Mediusers\CSpecCPAM;
use Hospilibre\Patients\CMedecin;

/**
 * Description
 */
class CFictifDoctorLegacyController extends CLegacyController
{
    /**
     * @throws \Exception
     *
     * @return void
     */
    public function add_edit_fictif_doctor(): void
    {
        $this->checkPermAdmin();

        $medecin_id = CView::get('doctor_id', 'ref class|CMedecin');

        CView::checkin();

        $medecin = CMedecin::findOrNew($medecin_id);

        $this->renderSmarty(
            'inc_edit_fictif_doctor',
            [
                'object'    => $medecin,
                'spec_cpam' => CSpecCPAM::getList(),
            ]
        );
    }
}
