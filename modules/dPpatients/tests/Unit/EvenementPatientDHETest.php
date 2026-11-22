<?php

/**
 * @package Hospilibre\Patients
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Patients\Tests\Unit;

use Ox\Core\CMbDT;
use Ox\Core\Kernel\Exception\RouteException;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Patients\CDossierMedical;
use Hospilibre\Patients\CEvenementPatient;
use Hospilibre\Patients\CPatient;
use Hospilibre\Patients\Services\EvenementPatientDHEService;
use Ox\Tests\UnitTestHospilibre;

class EvenementPatientDHETest extends UnitTestHospilibre
{
     /**
     * @dataProvider prepareResourcesProvider
     * @throws RouteException
     */
    public function testPrepareResourcesRelations(string $dhe_class, int $dhe_id, string $expected): void
    {
        $ep = $this->generateEvenemenPatient();
        $service  = new EvenementPatientDHEService($ep->_id);
        $actual = $service->constructRoute($dhe_class, $dhe_id);
        $this->assertTrue(str_contains($actual, $expected));
    }

    private function generateEvenemenPatient():CEvenementPatient
    {
        $patient = CPatient::getSampleObject();
        $this->storeOrFailed($patient);

        $ep = new CEvenementPatient();
        $ep->dossier_medical_id = CDossierMedical::dossierMedicalId($patient->_id, $patient->_class);
        $ep->date = CMbDT::dateTime();
        $ep->libelle = uniqid('libelle');
        $ep->owner_id = CMediusers::get()->_id;
        $this->storeOrFailed($ep);

        return $ep;
    }

    public function prepareResourcesProvider(): array
    {
        return [
            'sejour'    => [
                'CSejour',
                1,
                '/1?relations=praticien,patient,service&fieldsets=default,admission,sortie,annulation,urgences,placement,repas,cotation',
            ],
            'operation' => [
                'COperation',
                1,
                '/1?relations=praticien,patient,anesth&fieldsets=default,examen,timing,tarif,extra',
            ],
        ];
    }
}
