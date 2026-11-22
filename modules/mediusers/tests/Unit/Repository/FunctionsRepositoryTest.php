<?php

namespace Hospilibre\Mediusers\Tests\Unit\Repository;

use Exception;
use Ox\Core\CMbArray;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Mediusers\CFunctions;
use Hospilibre\Mediusers\Repository\FunctionsRepository;
use Ox\Tests\TestsException;
use Ox\Tests\UnitTestHospilibre;

class FunctionsRepositoryTest extends UnitTestHospilibre
{
    /**
     * Test to find all specialties
     *
     * @throws TestsException
     * @throws Exception
     */
    public function testFindAllSpecialties()
    {
        $function = CFunctions::getSampleObject();
        $function->group_id = CGroups::loadCurrent()->_id;
        $function->type = 'cabinet';
        $this->storeOrFailed($function);

        $function_repository = new FunctionsRepository();
        $functions = $function_repository->findAllSpecialties(null, 1);

        $function_ids = CMbArray::pluck($functions, "_id");

        $this->assertContains($function->_id, $function_ids);
    }
}
