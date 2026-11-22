<?php

namespace Hospilibre\Hospi\Tests\Unit\Repository;

use Exception;
use Ox\Core\CMbArray;
use Hospilibre\Hospi\CService;
use Hospilibre\Hospi\Repository\ServiceRepository;
use Hospilibre\Hospi\Tests\Fixtures\HospitalisationFixtures;
use Ox\Tests\TestsException;
use Ox\Tests\UnitTestHospilibre;

class ServiceRepositoryTest extends UnitTestHospilibre
{
    /**
     * Test to find all services not cancelled with perms
     *
     * @throws TestsException
     * @throws Exception
     */
    public function testFindAllNotCancelledWithPerms()
    {
        $service = $this->getObjectFromFixturesReference(
            CService::class,
            HospitalisationFixtures::SERVICE_HOSPITALISATION
        );

        $service_repository = new ServiceRepository();
        $services = $service_repository->findAllNotCancelledWithPerms();

        $service_ids = CMbArray::pluck($services, "_id");

        $this->assertContains($service->_id, $service_ids);
    }
}
