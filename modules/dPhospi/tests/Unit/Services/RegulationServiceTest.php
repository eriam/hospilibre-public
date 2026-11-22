<?php

namespace Hospilibre\Hospi\Tests\Unit\Repository;

use Exception;
use Ox\Core\CMbArray;
use Ox\Core\CMbDT;
use Hospilibre\Admin\Tests\Fixtures\UsersFixtures;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Hospi\Services\RegulationService;
use Hospilibre\Hospi\Tests\Fixtures\HospitalisationFixtures;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Patients\CPatient;
use Hospilibre\PlanningOp\CSejour;
use Ox\Tests\UnitTestHospilibre;

class RegulationServiceTest extends UnitTestHospilibre
{
    /** @var CMediusers */
    public static $medecin;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::$medecin = $this->getObjectFromFixturesReference(
            CMediusers::class,
            UsersFixtures::REF_USER_MEDECIN
        );
    }

    /**
     * Test to get Sejours by users action (created or modified) for less than 24 hours
     *
     * @throws Exception
     */
    public function testGetSejoursByUserAction()
    {
        $sejour = $this->getObjectFromFixturesReference(
            CSejour::class,
            HospitalisationFixtures::SEJOUR_HOSPITALISATION
        );

        $regulation_service = new RegulationService(null, CMbDT::dateTime());
        $sejours = $regulation_service->getSejoursByUserAction();

        $sejour_ids = CMbArray::pluck($sejours, "_id");

        if(count($sejour_ids)) {
            $this->assertContains($sejour->_id, $sejour_ids);
        }
        else {
            $this->assertNotContains($sejour->_id, $sejour_ids);
        }
    }

    /**
     * generate CSejour object
     *
     * @return CSejour
     * @throws Exception
     */
    public function generateSejour(): CSejour
    {
        /** @var CPatient $patient */
        $patient = CPatient::getSampleObject();
        $this->storeOrFailed($patient);

        /** @var CSejour $sejour */
        $sejour               = CSejour::getSampleObject();
        $sejour->patient_id   = $patient->_id;
        $sejour->praticien_id = self::$medecin->_id;
        $sejour->group_id     = CGroups::loadCurrent()->_id;
        $sejour->entree     = CGroups::loadCurrent()->_id;
        $this->storeOrFailed($sejour);

        return $sejour;
    }
}
