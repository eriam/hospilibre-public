<?php

namespace Hospilibre\Mediusers\Tests\Unit\Repository;

use Exception;
use Ox\Core\CMbArray;
use Hospilibre\Admin\Tests\Fixtures\UsersFixtures;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Mediusers\Repository\MediusersRepository;
use Ox\Tests\TestsException;
use Ox\Tests\UnitTestHospilibre;

class MediusersRepositoryTest extends UnitTestHospilibre
{
    /**
     * Test to find all practicioner
     *
     * @throws TestsException
     * @throws Exception
     */
    public function testFindAllPracticioner()
    {
        $mediuser_med = $this->getObjectFromFixturesReference(CMediusers::class, UsersFixtures::REF_USER_MEDECIN);
        $mediuser_anesth = $this->getObjectFromFixturesReference(CMediusers::class, UsersFixtures::REF_USER_ANESTH);

        $mediuser_repository = new MediusersRepository();
        $mediusers = $mediuser_repository->findAllPracticioner(false);

        $mediuser_ids = CMbArray::pluck($mediusers, "_id");

        $this->assertContains($mediuser_med->_id, $mediuser_ids);
        $this->assertContains($mediuser_anesth->_id, $mediuser_ids);
    }
}
