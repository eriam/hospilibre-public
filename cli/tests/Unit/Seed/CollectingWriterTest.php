<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Tests\Unit\Seed;

use Ox\Cli\Seed\CollectingWriter;
use Ox\Cli\Seed\SeedException;
use Ox\Core\CStoredObject;
use PHPUnit\Framework\TestCase;

/**
 * Couvre le comportement commun des chemins d'écriture (comptage, unicité des tags) à travers
 * l'implémentation qui n'écrit rien — celle du `--dry-run`.
 *
 * Les objets sont des doublures : construire un vrai CStoredObject exigerait le classmap et la
 * base, alors que ce qui est testé ici est la comptabilité du writer, pas l'ORM. Aucune base de
 * données n'est nécessaire.
 */
class CollectingWriterTest extends TestCase
{
    public function testItCountsByClassAndKeepsObjects(): void
    {
        $writer = new CollectingWriter();

        $writer->write($this->object('CLit'));
        $writer->write($this->object('CLit'));
        $writer->write($this->object('CChambre'));

        $this->assertSame(['CChambre' => 1, 'CLit' => 2], $writer->counts());
        $this->assertSame(3, $writer->total());
        $this->assertCount(2, $writer->of('CLit'));
        $this->assertCount(0, $writer->of('CService'));
    }

    public function testCountsAreSortedForAStableReport(): void
    {
        $writer = new CollectingWriter();

        $writer->write($this->object('CSejour'));
        $writer->write($this->object('CAffectation'));
        $writer->write($this->object('CPatient'));

        $this->assertSame(['CAffectation', 'CPatient', 'CSejour'], array_keys($writer->counts()));
    }

    public function testTaggedObjectsAreRetrievable(): void
    {
        $writer = new CollectingWriter();
        $bed    = $this->object('CLit');

        $writer->write($bed, 'lit:cardio-12');

        $this->assertSame($bed, $writer->tagged('lit:cardio-12'));
        $this->assertNull($writer->tagged('lit:inconnu'));
    }

    /**
     * Un tag est la promesse qu'une consigne d'exercice peut citer un objet précis. Deux objets
     * sous le même tag rendent cette promesse fausse, et rien en base ne l'empêcherait.
     */
    public function testDuplicateTagIsRefused(): void
    {
        $writer = new CollectingWriter();
        $writer->write($this->object('CLit'), 'lit:cardio-12');

        $this->expectException(SeedException::class);
        $this->expectExceptionMessageMatches('/Tag déjà attribué/');
        $writer->write($this->object('CLit'), 'lit:cardio-12');
    }

    /**
     * Le même tag sur deux classes différentes est légitime : « service:cardio » et
     * « lit:cardio » ne se confondent pas, et l'appelant récupère toujours par classe.
     */
    public function testSameTagOnAnotherClassIsAllowed(): void
    {
        $writer = new CollectingWriter();

        $writer->write($this->object('CLit'), 'cardio');
        $writer->write($this->object('CService'), 'cardio');

        $this->assertSame(2, $writer->total());
    }

    public function testUntaggedObjectsNeverCollide(): void
    {
        $writer = new CollectingWriter();

        for ($i = 0; $i < 50; $i++) {
            $writer->write($this->object('CPatient'));
        }

        $this->assertSame(50, $writer->total());
    }

    /**
     * Sur un profil `xl` (~100 000 objets), tout garder en mémoire n'a pas de sens : le dry-run
     * ne veut que les comptes.
     */
    public function testItCanCountWithoutKeeping(): void
    {
        $writer = new CollectingWriter(false);

        $writer->write($this->object('CPatient'));
        $writer->write($this->object('CPatient'));

        $this->assertSame(2, $writer->total());
        $this->assertSame([], $writer->written());
        $this->assertSame([], $writer->of('CPatient'));
    }

    public function testFlushIsANoOp(): void
    {
        $writer = new CollectingWriter();
        $writer->write($this->object('CLit'));

        $writer->flush();

        $this->assertSame(1, $writer->total());
    }

    public function testItAnnouncesItsName(): void
    {
        $this->assertSame('collect', (new CollectingWriter())->name());
    }

    private function object(string $class): CStoredObject
    {
        $object = $this->createMock(CStoredObject::class);
        $object->_class = $class;

        return $object;
    }
}
