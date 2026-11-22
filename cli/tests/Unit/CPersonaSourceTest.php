<?php

/**
 * @package Hospilibre\Populate
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Cli\Tests\Unit;

use Hospilibre\Populate\Generators\CPersonaSource;
use Ox\Tests\UnitTestHospilibre;

class CPersonaSourceTest extends UnitTestHospilibre
{
    protected function setUp(): void
    {
        parent::setUp();
        // Données factices contrôlées (pas les gros fichiers réels).
        $dir = sys_get_temp_dir() . '/persona_test_' . getmypid();
        @mkdir($dir, 0777, true);
        file_put_contents("$dir/prenoms.csv", "Marie;f\nJean;m\nCamille;f\n");
        file_put_contents("$dir/noms.csv", "Martin\nBernard\nDubois\n");
        CPersonaSource::setResourceDir($dir);
        CPersonaSource::resetCache();
    }

    public function testRandomPrenomRenvoieCoupleValide(): void
    {
        $p = CPersonaSource::randomPrenom();
        $this->assertArrayHasKey('prenom', $p);
        $this->assertArrayHasKey('sexe', $p);
        $this->assertContains($p['sexe'], ['f', 'm']);
        $this->assertContains($p['prenom'], ['Marie', 'Jean', 'Camille']);
    }

    public function testRandomNomNonVide(): void
    {
        $this->assertContains(CPersonaSource::randomNom(), ['Martin', 'Bernard', 'Dubois']);
    }

    public function testRepliSiFichierAbsent(): void
    {
        CPersonaSource::setResourceDir('/nonexistent_dir_xyz');
        CPersonaSource::resetCache();
        $p = CPersonaSource::randomPrenom();
        $this->assertNotSame('', $p['prenom']);   // repli synthétique, pas d'exception
        $this->assertNotSame('', CPersonaSource::randomNom());
    }

    public function testDeterministeAvecSeed(): void
    {
        mt_srand(42);
        $a = CPersonaSource::randomPrenom()['prenom'] . CPersonaSource::randomNom();
        mt_srand(42);
        $b = CPersonaSource::randomPrenom()['prenom'] . CPersonaSource::randomNom();
        $this->assertSame($a, $b);
    }

    protected function tearDown(): void
    {
        // Ne pas laisser le resourceDir de test (ou l'absence de fichiers) fuiter
        // vers d'autres classes de test exécutées dans le même process.
        CPersonaSource::setResourceDir(dirname(__DIR__, 3) . '/modules/populate/resources/personas');
        CPersonaSource::resetCache();

        parent::tearDown();
    }
}
