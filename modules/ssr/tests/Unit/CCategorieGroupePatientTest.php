<?php

/**
 * @package Hospilibre\Ssr
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Ssr\Test;

use Ox\Core\CMbException;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Ssr\CCategorieGroupePatient;
use Hospilibre\Ssr\CPlageGroupePatient;
use Ox\Tests\TestsException;
use Ox\Tests\UnitTestHospilibre;

class CCategorieGroupePatientTest extends UnitTestHospilibre
{
    /**
     * Test to create patient group category object
     *
     * @return CCategorieGroupePatient
     * @throws TestsException
     */
    public function testCreateCategorieGroupePatient(): CCategorieGroupePatient
    {
        $categorie_groupe = $this->getRandomObjects("CCategorieGroupePatient", 1);

        $this->assertInstanceOf(CCategorieGroupePatient::class, $categorie_groupe);
        $this->assertNotNull($categorie_groupe->_id);

        return $categorie_groupe;
    }

    /**
     * Test to load the group ranges
     */
    public function testLoadRefPlagesGroupe(): void
    {
        $category_groupe = CCategorieGroupePatient::getSampleObject();
        $category_groupe->group_id = CGroups::loadCurrent()->_id;
        if ($msg = $category_groupe->store()) {
            throw new CMbException($msg);
        }

        /** @var CPlageGroupePatient $plage */
        $plage_groupe = CPlageGroupePatient::getSampleObject();
        $plage_groupe->categorie_groupe_patient_id = $category_groupe->_id;
        if ($msg = $plage_groupe->store()) {
            throw new CMbException($msg);
        }

        $plages = $category_groupe->loadRefPlagesGroupe();

        $this->assertInstanceOf(CPlageGroupePatient::class, reset($plages));
        $this->assertEquals($category_groupe->_id, reset($plages)->categorie_groupe_patient_id);
    }

    /**
     * Test of update form field
     */
    public function testUpdateFormFields(): void
    {
        /** @var CCategorieGroupePatient $categorie_groupe */
        $categorie_groupe = $this->getRandomObjects("CCategorieGroupePatient", 1);
        $categorie_groupe->updateFormFields();

        $this->assertEquals($categorie_groupe->_view, $categorie_groupe->nom);
    }
}
