<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Tests\Unit\Seed;

use Ox\Cli\Seed\BedAllocator;
use Ox\Cli\Seed\SeedException;
use PHPUnit\Framework\TestCase;

/**
 * Aucune base de données n'est nécessaire.
 */
class BedAllocatorTest extends TestCase
{
    public function testAFreeBedAcceptsAnInterval(): void
    {
        $allocator = new BedAllocator();

        $this->assertTrue($allocator->isFree('lit:A1', '2026-03-01', '2026-03-05'));
        $this->assertTrue($allocator->allocate('lit:A1', '2026-03-01', '2026-03-05'));
        $this->assertSame(1, $allocator->allocatedBeds());
        $this->assertSame(1, $allocator->totalIntervals());
    }

    /**
     * @dataProvider overlappingIntervals
     */
    public function testOverlapIsRefused(string $from, string $to, string $case): void
    {
        $allocator = new BedAllocator();
        $allocator->allocate('lit:A1', '2026-03-10', '2026-03-20');

        $this->assertFalse($allocator->isFree('lit:A1', $from, $to), $case);
        $this->assertFalse($allocator->allocate('lit:A1', $from, $to), $case);

        // Un refus ne doit rien laisser derrière lui.
        $this->assertSame(1, $allocator->totalIntervals(), "{$case} : le refus a tout de même écrit");
    }

    public function overlappingIntervals(): array
    {
        return [
            'chevauchement par la gauche'  => ['2026-03-05', '2026-03-12', 'entre avant, sort pendant'],
            'chevauchement par la droite'  => ['2026-03-15', '2026-03-25', 'entre pendant, sort après'],
            'intervalle inclus'            => ['2026-03-12', '2026-03-15', 'entièrement dedans'],
            'intervalle englobant'         => ['2026-03-01', '2026-03-31', 'englobe l\'existant'],
            'identique'                    => ['2026-03-10', '2026-03-20', 'exactement le même'],
            'chevauchement d\'un instant'  => ['2026-03-19', '2026-03-21', 'ne partage qu\'un jour'],
        ];
    }

    /**
     * Intervalles SEMI-OUVERTS : un lit libéré est immédiatement réattribuable. C'est la
     * réalité d'un lit rendu le matin et réoccupé dans la journée, et le refuser ferait chuter
     * artificiellement le taux d'occupation.
     */
    public function testAdjacentIntervalsAreAccepted(): void
    {
        $allocator = new BedAllocator();

        $this->assertTrue($allocator->allocate('lit:A1', '2026-03-10 08:00:00', '2026-03-12 10:00:00'));
        $this->assertTrue($allocator->allocate('lit:A1', '2026-03-12 10:00:00', '2026-03-15 09:00:00'));
        $this->assertTrue($allocator->allocate('lit:A1', '2026-03-08 14:00:00', '2026-03-10 08:00:00'));

        $this->assertSame(3, $allocator->totalIntervals());
    }

    public function testBedsAreIndependent(): void
    {
        $allocator = new BedAllocator();

        $this->assertTrue($allocator->allocate('lit:A1', '2026-03-10', '2026-03-20'));
        $this->assertTrue($allocator->allocate('lit:A2', '2026-03-10', '2026-03-20'));

        $this->assertSame(2, $allocator->allocatedBeds());
    }

    public function testReleaseFreesTheBedAgain(): void
    {
        $allocator = new BedAllocator();
        $allocator->allocate('lit:A1', '2026-03-10', '2026-03-20');

        $this->assertTrue($allocator->release('lit:A1', '2026-03-10', '2026-03-20'));
        $this->assertTrue($allocator->isFree('lit:A1', '2026-03-10', '2026-03-20'));
        $this->assertSame(0, $allocator->allocatedBeds());
        $this->assertSame([], $allocator->intervals('lit:A1'));
    }

    public function testReleaseOnlyRemovesTheExactInterval(): void
    {
        $allocator = new BedAllocator();
        $allocator->allocate('lit:A1', '2026-03-10', '2026-03-20');
        $allocator->allocate('lit:A1', '2026-03-25', '2026-03-28');

        $this->assertFalse($allocator->release('lit:A1', '2026-03-10', '2026-03-19'), 'Borne différente');
        $this->assertFalse($allocator->release('lit:A9', '2026-03-10', '2026-03-20'), 'Lit inconnu');
        $this->assertSame(2, $allocator->totalIntervals());

        $this->assertTrue($allocator->release('lit:A1', '2026-03-10', '2026-03-20'));
        $this->assertSame([['2026-03-25', '2026-03-28']], $allocator->intervals('lit:A1'));
    }

    public function testFirstFreeWalksTheListInOrder(): void
    {
        $allocator = new BedAllocator();
        $allocator->allocate('lit:A1', '2026-03-10', '2026-03-20');
        $allocator->allocate('lit:A2', '2026-03-10', '2026-03-20');

        $beds = ['lit:A1', 'lit:A2', 'lit:A3', 'lit:A4'];

        $this->assertSame('lit:A3', $allocator->firstFree($beds, '2026-03-12', '2026-03-14'));
        $this->assertSame('lit:A1', $allocator->firstFree($beds, '2026-04-01', '2026-04-02'));
    }

    public function testFirstFreeReturnsNullWhenTheServiceIsFull(): void
    {
        $allocator = new BedAllocator();
        $allocator->allocate('lit:A1', '2026-03-10', '2026-03-20');
        $allocator->allocate('lit:A2', '2026-03-10', '2026-03-20');

        $this->assertNull($allocator->firstFree(['lit:A1', 'lit:A2'], '2026-03-12', '2026-03-14'));
    }

    public function testOccupiedAtCountsBedsNotIntervals(): void
    {
        $allocator = new BedAllocator();

        $allocator->allocate('lit:A1', '2026-03-01', '2026-03-10');
        $allocator->allocate('lit:A1', '2026-03-10', '2026-03-20');
        $allocator->allocate('lit:A2', '2026-03-05', '2026-03-07');

        $this->assertSame(2, $allocator->occupiedAt('2026-03-06'));
        $this->assertSame(1, $allocator->occupiedAt('2026-03-15'));
        $this->assertSame(0, $allocator->occupiedAt('2026-02-01'));

        // Borne haute exclue, borne basse incluse.
        $this->assertSame(1, $allocator->occupiedAt('2026-03-01'));
        $this->assertSame(0, $allocator->occupiedAt('2026-03-20'));
    }

    public function testDayOnlyBoundsBehaveAsMidnight(): void
    {
        $allocator = new BedAllocator();
        $allocator->allocate('lit:A1', '2026-03-10', '2026-03-12');

        $this->assertFalse($allocator->isFree('lit:A1', '2026-03-10 08:00:00', '2026-03-11 08:00:00'));
        $this->assertTrue($allocator->isFree('lit:A1', '2026-03-12 00:00:00', '2026-03-13 00:00:00'));
    }

    public function testInvertedIntervalIsAProgrammingError(): void
    {
        $this->expectException(SeedException::class);
        $this->expectExceptionMessageMatches('/inversé/');
        (new BedAllocator())->allocate('lit:A1', '2026-03-20', '2026-03-10');
    }

    public function testEmptyIntervalIsRefused(): void
    {
        $this->expectException(SeedException::class);
        (new BedAllocator())->allocate('lit:A1', '2026-03-10', '2026-03-10');
    }

    /**
     * La comparaison est lexicographique : un format exotique passerait inaperçu et casserait
     * l'invariant de non-chevauchement en silence. Autant refuser tout de suite.
     */
    public function testExoticDateFormatIsRefused(): void
    {
        $this->expectException(SeedException::class);
        $this->expectExceptionMessageMatches('/date SQL attendue/');
        (new BedAllocator())->allocate('lit:A1', '10/03/2026', '20/03/2026');
    }

    public function testResetEmptiesEverything(): void
    {
        $allocator = new BedAllocator();
        $allocator->allocate('lit:A1', '2026-03-10', '2026-03-20');

        $allocator->reset();

        $this->assertSame(0, $allocator->allocatedBeds());
        $this->assertSame(0, $allocator->totalIntervals());
    }

    /**
     * Le scénario que le motif des anciens générateurs ne pouvait pas garantir : sur une série
     * d'affectations tirées, AUCUN lit ne doit jamais porter deux occupants au même instant.
     */
    public function testNoOverlapSurvivesAFullSeason(): void
    {
        $allocator = new BedAllocator();
        $beds      = [];
        for ($i = 1; $i <= 12; $i++) {
            $beds[] = 'lit:' . $i;
        }

        $placed = 0;
        for ($day = 1; $day <= 28; $day++) {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $from = sprintf('2026-03-%02d', $day);
                $to   = sprintf('2026-03-%02d', min(28, $day + 1 + $attempt));

                if ($from >= $to) {
                    continue;
                }

                if ($allocator->firstFree($beds, $from, $to) !== null) {
                    $bed = $allocator->firstFree($beds, $from, $to);
                    $this->assertTrue($allocator->allocate($bed, $from, $to));
                    $placed++;
                }
            }
        }

        $this->assertGreaterThan(20, $placed, 'Le scénario doit réellement remplir des lits');

        // Vérification directe de l'invariant, sans passer par l'allocateur.
        foreach ($beds as $bed) {
            $intervals = $allocator->intervals($bed);
            foreach ($intervals as $i => $a) {
                foreach ($intervals as $j => $b) {
                    if ($i >= $j) {
                        continue;
                    }
                    $overlap = $a[0] < $b[1] && $a[1] > $b[0];
                    $this->assertFalse(
                        $overlap,
                        "Chevauchement sur {$bed} : [{$a[0]}, {$a[1]}[ et [{$b[0]}, {$b[1]}["
                    );
                }
            }
        }
    }
}
