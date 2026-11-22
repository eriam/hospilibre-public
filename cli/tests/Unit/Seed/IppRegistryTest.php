<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Tests\Unit\Seed;

use Hospilibre\Etablissement\CGroups;
use Hospilibre\Patients\CPatient;
use Ox\Cli\Seed\CollectingWriter;
use Ox\Cli\Seed\IppRegistry;
use Ox\Cli\Seed\SeedException;
use PHPUnit\Framework\TestCase;

/**
 * Couvre l'attribution des IPP.
 *
 * Le registre est employé en mode SIMULÉ : `bind()` n'y résout pas le tag par
 * `CPatient::getTagIPP()`, qui exigerait un établissement en base et la configuration de
 * l'instance. Ce qui est vérifié ici est l'arithmétique de la numérotation et le refus des
 * collisions — la résolution du tag, elle, appartient au socle.
 *
 * Les IPP sont écrits dans un chemin d'écriture qui ne va pas en base, et comptés : c'est ainsi
 * qu'on vérifie qu'il y en a bien un par patient, sans dépendre d'un `store()`.
 */
class IppRegistryTest extends TestCase
{
    public function testItNumbersTheMassSequentiallyFromTheStart(): void
    {
        $writer   = new CollectingWriter();
        $registry = $this->registry($writer, 'CHU-', 1000001);

        $this->assertSame('CHU-1000001', $registry->assign($this->patient(11)));
        $this->assertSame('CHU-1000002', $registry->assign($this->patient(12)));
        $this->assertSame('CHU-1000003', $registry->assign($this->patient(13)));

        $this->assertSame(3, $registry->count());
        $this->assertSame(3, $writer->total());
        $this->assertSame(['CIdSante400' => 3], $writer->counts());
    }

    /**
     * LE DÉTERMINISME. Deux registres identiques, sollicités dans le même ordre, attribuent les
     * mêmes numéros aux mêmes patients. C'est ce qui permet à un range recréé d'être le même
     * hôpital, et aux documents des autres applications de pointer sur quelqu'un.
     */
    public function testTwoIdenticalRunsAssignTheSameNumbers(): void
    {
        $first  = $this->registry(new CollectingWriter(), 'CHU-', 1000001);
        $second = $this->registry(new CollectingWriter(), 'CHU-', 1000001);

        $one = [];
        $two = [];

        foreach ([11, 12, 13, 14, 15] as $id) {
            $one[] = $first->assign($this->patient($id));
            $two[] = $second->assign($this->patient($id));
        }

        $this->assertSame($one, $two);
    }

    public function testTheWrittenIdexCarriesTheTagAndTheNumber(): void
    {
        $writer   = new CollectingWriter();
        $registry = $this->registry($writer, 'CHU-', 1000001);
        $patient  = $this->patient(42);

        $registry->assign($patient);

        $idexes = $writer->of('CIdSante400');
        $this->assertCount(1, $idexes);

        $idex = reset($idexes);
        $this->assertSame('CHU-1000001', $idex->id400);
        $this->assertSame('CPatient', $idex->object_class);
        $this->assertSame(42, (int)$idex->object_id);
        $this->assertSame($registry->tag(), $idex->tag);

        // L'IPP est aussi posé sur le patient en mémoire : l'export et les builders suivants le
        // lisent sans recharger ses identifiants externes.
        $this->assertSame('CHU-1000001', $patient->_IPP);
    }

    /**
     * LA PROPRIÉTÉ CENTRALE. Un IPP imposé par un scénario peut tomber n'importe où, y compris dans
     * la plage de la masse. C'est à la masse de s'écarter, jamais au scénario.
     */
    public function testTheMassSkipsAnImposedNumberInItsOwnRange(): void
    {
        $registry = $this->registry(new CollectingWriter(), 'CHU-', 1000001);

        $registry->reserve('CHU-1000002');

        $this->assertSame('CHU-1000001', $registry->assign($this->patient(11)));
        $this->assertSame('CHU-1000003', $registry->assign($this->patient(12)));
    }

    public function testAnImposedNumberIsHonouredVerbatim(): void
    {
        $registry = $this->registry(new CollectingWriter(), 'CHU-', 1000001);

        $registry->reserve('CHU-0007742');

        $this->assertSame('CHU-0007742', $registry->assign($this->patient(11), 'CHU-0007742'));
        $this->assertSame('CHU-1000001', $registry->assign($this->patient(12)));
    }

    public function testTheSameImposedNumberTwiceIsRefused(): void
    {
        $registry = $this->registry(new CollectingWriter(), 'CHU-', 1000001);

        $registry->reserve('CHU-0007742');

        $this->expectException(SeedException::class);
        $this->expectExceptionMessageMatches('/imposé deux fois/');

        $registry->reserve('CHU-0007742');
    }

    public function testANumberAlreadyCarriedIsRefused(): void
    {
        $registry = $this->registry(new CollectingWriter(), 'CHU-', 1000001);

        $registry->assign($this->patient(11));

        $this->expectException(SeedException::class);
        $this->expectExceptionMessageMatches('/déjà porté par le patient #11/');

        $registry->assign($this->patient(12), 'CHU-1000001');
    }

    public function testAnUnwrittenPatientCannotCarryAnIpp(): void
    {
        $registry = $this->registry(new CollectingWriter(), 'CHU-', 1000001);

        $this->expectException(SeedException::class);
        $this->expectExceptionMessageMatches("/n'est pas encore écrit/");

        $registry->assign(new CPatient());
    }

    public function testBindIsMandatory(): void
    {
        $registry = new IppRegistry(new CollectingWriter(), 'CHU-', 1000001, true);

        $this->expectException(SeedException::class);
        $this->expectExceptionMessageMatches('/bind\(\)/');

        $registry->assign($this->patient(11));
    }

    public function testTheMassRangeIsReportable(): void
    {
        $registry = $this->registry(new CollectingWriter(), 'IPP', 500);

        $registry->assign($this->patient(11));
        $registry->assign($this->patient(12));

        $this->assertSame(['IPP', 502, 500], $registry->massRange());
    }

    /**
     * @throws SeedException
     */
    private function registry(CollectingWriter $writer, string $prefix, int $start): IppRegistry
    {
        // Mode simulé : `bind()` n'interroge ni la base ni la configuration. L'argument
        // d'établissement n'est donc pas lu, et un objet neuf suffit.
        $registry = new IppRegistry($writer, $prefix, $start, true);
        $registry->bind(new CGroups());

        return $registry;
    }

    private function patient(int $id): CPatient
    {
        $patient      = new CPatient();
        $patient->_id = $id;

        return $patient;
    }
}
