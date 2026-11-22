<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Tests\Unit\Seed;

use Ox\Cli\Seed\CommuneDictionary;
use Ox\Cli\Seed\SeedRandom;
use Ox\Tests\UnitTestHospilibre;

/**
 * Le dictionnaire des communes, et la cohérence géographique qu'il permet.
 *
 * Ce test s'exécute sur la source disponible, quelle qu'elle soit — la datasource `INSEE` si elle est
 * déclarée, le fichier de ressources sinon. Les deux portent les mêmes données, et ce qui est vérifié
 * ici ne dépend pas de laquelle répond : un hôpital dans une ville, des patients autour.
 */
class CommuneDictionaryTest extends UnitTestHospilibre
{
    protected function setUp(): void
    {
        parent::setUp();

        CommuneDictionary::reset();
    }

    public function testTheDictionaryIsAvailableAndSaysWhereItComesFrom(): void
    {
        $dictionary = new CommuneDictionary();

        $this->assertTrue(
            $dictionary->isAvailable(),
            'Le fichier de ressources du module openData est livré dans le dépôt : il doit suffire'
        );

        $source = $dictionary->describe();

        $this->assertNotSame('aucun dictionnaire : communes inventées', $source);
        $this->assertMatchesRegularExpression('/\d+ communes/', $source, $source);
    }

    /**
     * L'établissement s'implante dans une VILLE, pas dans un hameau.
     */
    public function testTheHospitalSitsInATownBigEnoughToHaveOne(): void
    {
        $dictionary = new CommuneDictionary();
        $town       = $dictionary->pickHospitalTown(new SeedRandom(20260925));

        $this->assertNotNull($town);
        $this->assertNotSame('', $town['commune']);
        $this->assertGreaterThanOrEqual(
            15000,
            (int)$town['population'],
            "{$town['commune']} ne peut pas porter un centre hospitalier"
        );
        $this->assertMatchesRegularExpression('/^\d{4,5}$/', $town['code_postal'], $town['commune']);
    }

    /**
     * LE POINT DE TOUT CECI : les patients viennent du territoire de l'établissement.
     *
     * Les communes étaient inventées et tirées uniformément : un hôpital de Bordeaux recrutait
     * indifféremment à Valmorin et à Noirvaux, qui n'existent ni l'un ni l'autre.
     */
    public function testPatientsComeFromTheHospitalTerritory(): void
    {
        $dictionary = new CommuneDictionary();
        $random     = new SeedRandom(4242);
        $hospital   = $dictionary->pickHospitalTown($random);

        $this->assertNotNull($hospital);

        $same_department = 0;
        $same_region     = 0;
        $towns           = [];

        for ($i = 0; $i < 400; $i++) {
            $town = $dictionary->pickPatientTown($random, $hospital);

            $this->assertNotNull($town);
            $towns[$town['commune']] = true;

            if ($town['code_departement'] === $hospital['code_departement']) {
                $same_department++;
            }

            // Hors du département, on reste dans la RÉGION : c'est la limite du recrutement.
            $this->assertSame($hospital['code_region'], $town['code_region'], $town['commune']);

            if ($town['code_region'] === $hospital['code_region']) {
                $same_region++;
            }
        }

        $this->assertSame(400, $same_region, 'Aucun patient ne doit venir d\'une autre région');
        $this->assertGreaterThan(300, $same_department, 'La grande majorité vient du département');
        $this->assertLessThan(400, $same_department, 'Mais pas la totalité : on se déplace aussi');

        // Et la population pondère : une poignée de communes ne doit pas absorber tout le monde,
        // mais la ville-centre doit dominer.
        $this->assertGreaterThan(10, count($towns), 'Les patients doivent venir de communes variées');
    }

    /**
     * LES DEUX SOURCES DOIVENT PORTER LES MÊMES DONNÉES.
     *
     * La table de la datasource `INSEE` est alimentée à partir du même fichier que celui livré dans
     * les ressources du module. Si les deux divergeaient, le jeu de données dépendrait de
     * l'installation — un hôpital à Écully ici, ailleurs là-bas, à graine égale. Ce test compare donc
     * ce que chaque source rend, sur le même tirage.
     *
     * Il ne vérifie quelque chose que si la datasource est joignable ; sinon il le dit et s'arrête,
     * plutôt que de passer en silence.
     */
    public function testBothSourcesCarryTheSameData(): void
    {
        CommuneDictionary::useSource('database');
        $from_database = new CommuneDictionary();

        if (!$from_database->isAvailable()) {
            $this->markTestSkipped(
                "Datasource INSEE absente : l'équivalence des deux sources n'est pas vérifiable ici."
            );
        }

        $database = $from_database->pickHospitalTown(new SeedRandom(31337));

        CommuneDictionary::useSource('archive');
        $from_archive = new CommuneDictionary();

        $this->assertTrue($from_archive->isAvailable(), 'Le fichier de ressources doit être lisible');
        $this->assertStringContainsString('.zip', $from_archive->describe());

        $archive = $from_archive->pickHospitalTown(new SeedRandom(31337));

        $this->assertSame(
            $database,
            $archive,
            'Les deux sources doivent rendre la même commune pour le même tirage'
        );
    }

    /**
     * À graine égale, les mêmes communes : c'est la propriété que toute la mécanique du seed protège.
     */
    public function testTheSameSeedDrawsTheSameTowns(): void
    {
        $dictionary = new CommuneDictionary();

        $draw = static function (CommuneDictionary $dictionary, int $seed): array {
            $random   = new SeedRandom($seed);
            $hospital = $dictionary->pickHospitalTown($random);
            $towns    = [$hospital['commune']];

            for ($i = 0; $i < 20; $i++) {
                $towns[] = $dictionary->pickPatientTown($random, $hospital)['commune'];
            }

            return $towns;
        };

        $this->assertSame($draw($dictionary, 7), $draw($dictionary, 7));
        $this->assertNotSame($draw($dictionary, 7), $draw($dictionary, 8));
    }
}
