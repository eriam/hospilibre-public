<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Tests\Unit\Seed;

use Ox\Cli\Seed\SeedException;
use Ox\Cli\Seed\SeedRandom;
use PHPUnit\Framework\TestCase;

/**
 * Les séquences attendues sont écrites EN DUR, et c'est le but : ce test ne vérifie pas que le
 * générateur « produit de l'aléa », il verrouille la suite exacte qu'il produit. Si une
 * modification du générateur la change, tous les jeux de données déjà cités dans des consignes
 * d'exercice deviennent faux — le test doit donc échouer bruyamment, et le changement être une
 * décision assumée.
 *
 * Aucune base de données n'est nécessaire.
 */
class SeedRandomTest extends TestCase
{
    /** Séquence brute pour la graine par défaut, relevée à l'écriture du générateur. */
    private const DEFAULT_SEQUENCE = [
        577347236,
        639621434,
        2049311590,
        4078523939,
        3799384941,
        1634598496,
        177452848,
        4072974410,
    ];

    /** Séquence brute pour la graine 1 : vérifie que le petit état initial se diffuse bien. */
    private const SEED_ONE_SEQUENCE = [
        270369,
        67634689,
        2647435461,
        307599695,
        2398689233,
        745495504,
        632435482,
        435756210,
    ];

    public function testDefaultSeedIsStable(): void
    {
        $this->assertSame(20260925, SeedRandom::DEFAULT_SEED, 'Changer la graine par défaut change TOUTES les données');

        $random = new SeedRandom();
        $this->assertSame(SeedRandom::DEFAULT_SEED, $random->seed());

        $drawn = [];
        for ($i = 0; $i < count(self::DEFAULT_SEQUENCE); $i++) {
            $drawn[] = $random->nextInt();
        }

        $this->assertSame(self::DEFAULT_SEQUENCE, $drawn);
    }

    public function testSmallSeedDiffusesIntoTheSequence(): void
    {
        $random = new SeedRandom(1);

        $drawn = [];
        for ($i = 0; $i < count(self::SEED_ONE_SEQUENCE); $i++) {
            $drawn[] = $random->nextInt();
        }

        $this->assertSame(self::SEED_ONE_SEQUENCE, $drawn);
    }

    public function testDrawsStayInThirtyTwoBits(): void
    {
        $random = new SeedRandom(0xDEADBEEF);

        for ($i = 0; $i < 500; $i++) {
            $draw = $random->nextInt();
            $this->assertGreaterThanOrEqual(0, $draw);
            $this->assertLessThanOrEqual(0xFFFFFFFF, $draw);
        }
    }

    public function testSameSeedGivesSameSequence(): void
    {
        $first  = new SeedRandom(4242);
        $second = new SeedRandom(4242);

        for ($i = 0; $i < 100; $i++) {
            $this->assertSame($first->nextInt(), $second->nextInt());
        }
    }

    public function testDifferentSeedsDiverge(): void
    {
        $first  = new SeedRandom(4242);
        $second = new SeedRandom(4243);

        $identical = 0;
        for ($i = 0; $i < 50; $i++) {
            if ($first->nextInt() === $second->nextInt()) {
                $identical++;
            }
        }

        $this->assertLessThan(3, $identical, 'Deux graines voisines ne doivent pas produire la même suite');
    }

    /**
     * 0 est un point fixe de xorshift : sans repli, une graine nulle ne produirait que des 0.
     * La graine RAPPORTÉE reste 0, parce que c'est bien ce que l'opérateur a demandé et que le
     * jeu de données reste reproductible.
     */
    public function testZeroSeedIsUsableAndStillReported(): void
    {
        $random = new SeedRandom(0);

        $this->assertSame(0, $random->seed());

        $draws = [];
        for ($i = 0; $i < 5; $i++) {
            $draws[] = $random->nextInt();
        }

        $this->assertNotSame([0, 0, 0, 0, 0], $draws, 'Une graine nulle ne doit pas figer le générateur');

        $again   = new SeedRandom(0);
        $redrawn = [];
        for ($i = 0; $i < 5; $i++) {
            $redrawn[] = $again->nextInt();
        }

        $this->assertSame($draws, $redrawn, 'Le repli doit rester déterministe');
    }

    public function testSeedIsTruncatedToThirtyTwoBits(): void
    {
        $truncated = new SeedRandom(0x1_0000_0001);
        $reference = new SeedRandom(1);

        $this->assertSame($reference->seed(), $truncated->seed());
        $this->assertSame($reference->nextInt(), $truncated->nextInt());
    }

    public function testIntRespectsBounds(): void
    {
        $random = new SeedRandom(42);

        $expected = [1, 5, 4, 3, 1, 1, 1, 5, 2, 6, 5, 2];

        $drawn = [];
        for ($i = 0; $i < count($expected); $i++) {
            $drawn[] = $random->int(1, 6);
        }

        $this->assertSame($expected, $drawn);
    }

    public function testIntWithSingleValueConsumesNothing(): void
    {
        $random = new SeedRandom(42);

        $this->assertSame(7, $random->int(7, 7));
        // Le tirage dégénéré ne doit pas consommer l'état, sinon ajouter un appel trivial
        // décalerait toute la suite.
        $this->assertSame(1, $random->int(1, 6));
    }

    public function testIntRejectsEmptyRange(): void
    {
        $this->expectException(SeedException::class);
        (new SeedRandom())->int(10, 9);
    }

    public function testIntIsUniform(): void
    {
        $random = new SeedRandom(SeedRandom::DEFAULT_SEED);

        $histogram = array_fill(1, 6, 0);
        for ($i = 0; $i < 60000; $i++) {
            $histogram[$random->int(1, 6)]++;
        }

        foreach ($histogram as $face => $count) {
            $this->assertGreaterThan(9500, $count, "Face {$face} sous-représentée");
            $this->assertLessThan(10500, $count, "Face {$face} sur-représentée");
        }
    }

    public function testFloatStaysInUnitInterval(): void
    {
        $random = new SeedRandom(99);

        for ($i = 0; $i < 1000; $i++) {
            $value = $random->float();
            $this->assertGreaterThanOrEqual(0.0, $value);
            $this->assertLessThan(1.0, $value);
        }
    }

    public function testChanceInThousandHonoursItsRate(): void
    {
        $random = new SeedRandom(SeedRandom::DEFAULT_SEED);

        $hits = 0;
        for ($i = 0; $i < 100000; $i++) {
            if ($random->chanceInThousand(300)) {
                $hits++;
            }
        }

        $this->assertGreaterThan(29000, $hits);
        $this->assertLessThan(31000, $hits);
    }

    public function testChanceInThousandEdgeCases(): void
    {
        $random = new SeedRandom(5);

        $this->assertFalse($random->chanceInThousand(0));
        $this->assertTrue($random->chanceInThousand(1000));
    }

    public function testChanceInThousandRejectsOutOfBoundsRate(): void
    {
        $this->expectException(SeedException::class);
        (new SeedRandom())->chanceInThousand(1001);
    }

    public function testPickIgnoresKeys(): void
    {
        $random = new SeedRandom(11);

        $picked = $random->pick(['a' => 'lit', 'z' => 'chambre', 'k' => 'service']);
        $this->assertContains($picked, ['lit', 'chambre', 'service']);
    }

    public function testPickRejectsEmptyCollection(): void
    {
        $this->expectException(SeedException::class);
        (new SeedRandom())->pick([]);
    }

    public function testPickWeightedFollowsTheMix(): void
    {
        $random = new SeedRandom(7);

        $expected = ['urg', 'comp', 'ambu', 'comp', 'comp', 'comp', 'comp', 'urg', 'comp', 'ambu'];

        $drawn = [];
        for ($i = 0; $i < count($expected); $i++) {
            $drawn[] = $random->pickWeighted(['ambu' => 40, 'comp' => 35, 'urg' => 25]);
        }

        $this->assertSame($expected, $drawn);
    }

    public function testPickWeightedConvergesToTheDeclaredProportions(): void
    {
        $mix    = ['ambu' => 40, 'comp' => 35, 'urg' => 10, 'exte' => 8, 'seances' => 4, 'consult' => 3];
        $random = new SeedRandom(SeedRandom::DEFAULT_SEED);

        $histogram = array_fill_keys(array_keys($mix), 0);
        for ($i = 0; $i < 100000; $i++) {
            $histogram[$random->pickWeighted($mix)]++;
        }

        foreach ($mix as $key => $percent) {
            $observed = $histogram[$key] / 1000;
            $this->assertLessThan(
                0.7,
                abs($observed - $percent),
                "Proportion de '{$key}' : {$observed} % au lieu de {$percent} %"
            );
        }
    }

    public function testPickWeightedRejectsNonIntegerWeights(): void
    {
        $this->expectException(SeedException::class);
        (new SeedRandom())->pickWeighted(['ambu' => 0.4, 'comp' => 0.6]);
    }

    public function testPickWeightedRejectsNullTotal(): void
    {
        $this->expectException(SeedException::class);
        (new SeedRandom())->pickWeighted(['ambu' => 0, 'comp' => 0]);
    }

    public function testShuffleIsAPermutationAndLeavesInputAlone(): void
    {
        $input  = [1, 2, 3, 4, 5, 6, 7, 8];
        $random = new SeedRandom(3);

        $shuffled = $random->shuffle($input);

        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8], $input, "shuffle() ne doit pas modifier son entrée");
        $this->assertSame([1, 7, 2, 5, 6, 8, 3, 4], $shuffled);

        $sorted = $shuffled;
        sort($sorted);
        $this->assertSame($input, $sorted);
    }

    public function testShuffleIsUnbiased(): void
    {
        $random    = new SeedRandom(1);
        $positions = array_fill(0, 6, 0);

        for ($i = 0; $i < 60000; $i++) {
            foreach ($random->shuffle([0, 1, 2, 3, 4, 5]) as $position => $value) {
                $positions[$value] += $position;
            }
        }

        foreach ($positions as $value => $total) {
            $average = $total / 60000;
            $this->assertLessThan(
                0.05,
                abs($average - 2.5),
                "L'élément {$value} occupe en moyenne la position {$average} au lieu de 2.5"
            );
        }
    }

    public function testTakeReturnsDistinctElements(): void
    {
        $random = new SeedRandom(3);

        $taken = $random->take([1, 2, 3, 4, 5, 6, 7, 8], 3);

        $this->assertSame([1, 7, 2], $taken);
        $this->assertSame($taken, array_unique($taken));
    }

    public function testTakeRejectsTooManyElements(): void
    {
        $this->expectException(SeedException::class);
        (new SeedRandom())->take([1, 2, 3], 4);
    }

    public function testTakeRejectsNegativeCount(): void
    {
        $this->expectException(SeedException::class);
        (new SeedRandom())->take([1, 2, 3], -1);
    }

    /**
     * Les sous-générateurs sont ce qui permet d'ajouter un tirage aux patients sans changer les
     * séjours. Leurs graines doivent donc être stables, et distinctes d'une étiquette à l'autre.
     */
    public function testDeriveIsStableAndLabelDependent(): void
    {
        $this->assertSame(607543724, (new SeedRandom(123))->derive('patients')->seed());
        $this->assertSame(2428388605, (new SeedRandom(123))->derive('sejours')->seed());

        $this->assertSame(
            (new SeedRandom(123))->derive('patients')->seed(),
            (new SeedRandom(123))->derive('patients')->seed()
        );

        $this->assertNotSame(
            (new SeedRandom(123))->derive('patients')->seed(),
            (new SeedRandom(124))->derive('patients')->seed()
        );
    }

    public function testDerivedStreamsAreIndependent(): void
    {
        $parent = new SeedRandom(555);

        $patients = $parent->derive('patients');
        $sejours  = $parent->derive('sejours');

        // Consommer le flux des patients ne doit rien changer à celui des séjours.
        $expected = [];
        for ($i = 0; $i < 5; $i++) {
            $expected[] = $sejours->nextInt();
        }

        $patients->take(range(1, 20), 10);

        $fresh = (new SeedRandom(555))->derive('sejours');
        $again = [];
        for ($i = 0; $i < 5; $i++) {
            $again[] = $fresh->nextInt();
        }

        $this->assertSame($expected, $again);
    }
}
