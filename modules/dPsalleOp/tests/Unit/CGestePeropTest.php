<?php

namespace Hospilibre\SalleOp;

use Hospilibre\Etablissement\CGroups;
use Hospilibre\Populate\Generators\CFileGenerator;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\SalleOp\Generators\CGestePeropGenerator;
use Ox\Tests\UnitTestHospilibre;

class CGestePeropTest extends UnitTestHospilibre {

    /** @var CGestePerop */
    protected static $geste_perop;

    /**
     * @inheritDoc
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        static::$geste_perop = (new CGestePeropGenerator())->generate();
    }

  /**
   * Test label in the _view variable
   */
  public function testLabelInView() {
    static::$geste_perop->updateFormFields();

    $this->assertEquals(static::$geste_perop->libelle, static::$geste_perop->_view);
  }

  /**
   * Test to load files for object
   */
  /*public function testLoadRefFile() {
      $file_generator = new CFileGenerator();
      $file_generator->init(static::$geste_perop, CMediusers::get()->_id);
      $file_generator->generate();

      static::$geste_perop->loadRefFile();
      $this->assertFileExists(static::$geste_perop->_ref_file->_file_path);
  }*/

    /**
     * Test to load object by chapter
     */
    public function testLoadGestesByChapitre() {
        $gestes_chapter = static::$geste_perop->loadGestesByChapitre('group_id', static::$geste_perop->group_id);

        $this->assertArrayHasKey("common-No chapter", $gestes_chapter);
        $this->assertArrayHasKey("common-No category", $gestes_chapter["common-No chapter"]);
        $this->assertNotEmpty($gestes_chapter["common-No chapter"]["common-No category"][0]->_id);
    }
}
