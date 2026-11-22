<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Tests\Unit\Seed;

use Ox\Cli\Seed\AnchoredCohort;
use Ox\Cli\Seed\SeedException;
use PHPUnit\Framework\TestCase;

/**
 * Couvre la lecture du fichier d'identités imposées.
 *
 * Deux propriétés valent plus que toutes les autres, et elles se contredisent en apparence :
 * l'IPP doit être unique, et les HOMONYMES doivent passer. Un scénario met deux LEROY Jean dans sa
 * liste exprès, pour que le rapprochement par nom échoue visiblement et que le lien se fasse par
 * l'IPP. Une déduplication par le nom les fusionnerait et supprimerait l'épreuve.
 *
 * Aucune base de données n'est nécessaire : cette classe lit un fichier et valide.
 */
class AnchoredCohortTest extends TestCase
{
    /** @var string[] Fichiers temporaires à effacer */
    private $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        $this->files = [];
    }

    public function testItReadsTheRequiredColumns(): void
    {
        $cohort = AnchoredCohort::fromCsv(
            $this->csv(
                "ipp,nom,prenom,naissance,sexe\n"
                . "CHU-0007742,MARTIN,Sophie,1979-03-14,f\n"
                . "CHU-0007743,BERNARD,Ahmed,1951-11-02,m\n"
            )
        );

        $this->assertSame(2, $cohort->count());
        $this->assertFalse($cohort->isEmpty());
        $this->assertSame(['CHU-0007742', 'CHU-0007743'], $cohort->ipps());

        $first = $cohort->anchors()[0];
        $this->assertSame('MARTIN', $first['nom']);
        $this->assertSame('Sophie', $first['prenom']);
        $this->assertSame('1979-03-14', $first['naissance']);
        $this->assertSame('f', $first['sexe']);

        // Colonnes facultatives absentes : présentes et vides, et non manquantes — l'appelant les
        // lit sans avoir à se demander si la clé existe.
        $this->assertSame('', $first['service']);
        $this->assertSame('', $first['motif_sejour']);
    }

    /**
     * LE CAS QUI COMPTE. Deux homonymes stricts — mêmes nom, prénom, naissance et sexe — ne
     * diffèrent que par leur IPP, et doivent tous les deux survivre à la lecture.
     */
    public function testHomonymsAreKept(): void
    {
        $cohort = AnchoredCohort::fromCsv(
            $this->csv(
                "ipp,nom,prenom,naissance,sexe\n"
                . "CHU-0007761,LEROY,Jean,1962-05-08,m\n"
                . "CHU-0007768,LEROY,Jean,1962-05-08,m\n"
            )
        );

        $this->assertSame(2, $cohort->count());
        $this->assertSame(['CHU-0007761', 'CHU-0007768'], $cohort->ipps());
    }

    public function testDuplicateIppIsRefused(): void
    {
        $this->expectException(SeedException::class);
        $this->expectExceptionMessageMatches('/apparaît déjà ligne 2/');

        AnchoredCohort::fromCsv(
            $this->csv(
                "ipp,nom,prenom,naissance,sexe\n"
                . "CHU-0007761,LEROY,Jean,1962-05-08,m\n"
                . "CHU-0007761,DUPONT,Marie,1988-01-20,f\n"
            )
        );
    }

    public function testOptionalClinicalColumnsAreRead(): void
    {
        $cohort = AnchoredCohort::fromCsv(
            $this->csv(
                "ipp,nom,prenom,naissance,sexe,service,motif_sejour\n"
                . "CHU-0007742,MARTIN,Sophie,1979-03-14,f,cardiologie,Douleur thoracique\n"
                . "CHU-0007743,BERNARD,Ahmed,1951-11-02,m,,\n"
            )
        );

        $this->assertSame('cardiologie', $cohort->anchors()[0]['service']);
        $this->assertSame('Douleur thoracique', $cohort->anchors()[0]['motif_sejour']);
        $this->assertSame('', $cohort->anchors()[1]['service']);

        // Seuls les services RÉELLEMENT imposés sont rendus : la ligne sans service laisse le choix
        // au seed, et ne doit pas faire échouer la vérification d'existence.
        $this->assertSame(['cardiologie'], $cohort->requestedServices());
    }

    public function testColumnOrderDoesNotMatter(): void
    {
        $cohort = AnchoredCohort::fromCsv(
            $this->csv(
                "sexe,prenom,ipp,naissance,nom\n"
                . "f,Sophie,CHU-0007742,1979-03-14,MARTIN\n"
            )
        );

        $anchor = $cohort->anchors()[0];
        $this->assertSame('CHU-0007742', $anchor['ipp']);
        $this->assertSame('MARTIN', $anchor['nom']);
        $this->assertSame('f', $anchor['sexe']);
    }

    public function testSemicolonDelimiterIsDetected(): void
    {
        $cohort = AnchoredCohort::fromCsv(
            $this->csv(
                "ipp;nom;prenom;naissance;sexe\n"
                . "CHU-0007742;MARTIN;Sophie;14/03/1979;femme\n"
            )
        );

        $this->assertSame(1, $cohort->count());
        $this->assertSame('1979-03-14', $cohort->anchors()[0]['naissance']);
        $this->assertSame('f', $cohort->anchors()[0]['sexe']);
    }

    /**
     * Le BOM d'un tableur Windows collerait au premier nom de colonne et ferait manquer « ipp »,
     * pour une raison invisible à l'œil dans un éditeur.
     */
    public function testByteOrderMarkDoesNotHideTheFirstColumn(): void
    {
        $cohort = AnchoredCohort::fromCsv(
            $this->csv(
                "\xEF\xBB\xBFipp,nom,prenom,naissance,sexe\n"
                . "CHU-0007742,MARTIN,Sophie,1979-03-14,f\n"
            )
        );

        $this->assertSame('CHU-0007742', $cohort->anchors()[0]['ipp']);
    }

    /**
     * @dataProvider sexes
     */
    public function testSexIsNormalised(string $raw, string $expected): void
    {
        $cohort = AnchoredCohort::fromCsv(
            $this->csv("ipp,nom,prenom,naissance,sexe\nX-1,MARTIN,Sophie,1979-03-14,{$raw}\n")
        );

        $this->assertSame($expected, $cohort->anchors()[0]['sexe']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function sexes(): array
    {
        return [
            'minuscule'  => ['f', 'f'],
            'majuscule'  => ['M', 'm'],
            'homme'      => ['homme', 'm'],
            'femme'      => ['Femme', 'f'],
            'indetermine' => ['i', 'i'],
        ];
    }

    public function testMissingRequiredColumnIsNamed(): void
    {
        $this->expectException(SeedException::class);
        $this->expectExceptionMessageMatches('/colonne\(s\) obligatoire\(s\) absente\(s\) : naissance/');

        AnchoredCohort::fromCsv($this->csv("ipp,nom,prenom,sexe\nX-1,MARTIN,Sophie,f\n"));
    }

    public function testEmptyRequiredValueIsRefused(): void
    {
        $this->expectException(SeedException::class);
        $this->expectExceptionMessageMatches("/la colonne 'prenom' est vide/");

        AnchoredCohort::fromCsv(
            $this->csv("ipp,nom,prenom,naissance,sexe\nX-1,MARTIN,,1979-03-14,f\n")
        );
    }

    public function testImpossibleBirthDateIsRefused(): void
    {
        $this->expectException(SeedException::class);
        $this->expectExceptionMessageMatches('/inexistante/');

        AnchoredCohort::fromCsv(
            $this->csv("ipp,nom,prenom,naissance,sexe\nX-1,MARTIN,Sophie,1979-02-30,f\n")
        );
    }

    public function testFutureBirthDateIsRefused(): void
    {
        $this->expectException(SeedException::class);
        $this->expectExceptionMessageMatches('/dans le futur/');

        AnchoredCohort::fromCsv(
            $this->csv("ipp,nom,prenom,naissance,sexe\nX-1,MARTIN,Sophie,2999-02-01,f\n")
        );
    }

    public function testUnreadableSexIsRefusedWithItsLineNumber(): void
    {
        $this->expectException(SeedException::class);
        $this->expectExceptionMessageMatches('/ligne 3 : sexe .* non reconnu/');

        AnchoredCohort::fromCsv(
            $this->csv(
                "ipp,nom,prenom,naissance,sexe\n"
                . "X-1,MARTIN,Sophie,1979-03-14,f\n"
                . "X-2,BERNARD,Ahmed,1951-11-02,oui\n"
            )
        );
    }

    public function testHeaderOnlyFileIsRefused(): void
    {
        $this->expectException(SeedException::class);
        $this->expectExceptionMessageMatches('/aucune ligne de patient/');

        AnchoredCohort::fromCsv($this->csv("ipp,nom,prenom,naissance,sexe\n"));
    }

    public function testMissingFileIsRefused(): void
    {
        $this->expectException(SeedException::class);
        $this->expectExceptionMessageMatches('/introuvable ou illisible/');

        AnchoredCohort::fromCsv('/inexistant/ancres.csv');
    }

    public function testNoneIsEmptyAndHarmless(): void
    {
        $cohort = AnchoredCohort::none();

        $this->assertTrue($cohort->isEmpty());
        $this->assertSame(0, $cohort->count());
        $this->assertSame([], $cohort->anchors());
        $this->assertSame([], $cohort->ipps());
        $this->assertSame([], $cohort->requestedServices());
    }

    private function csv(string $content): string
    {
        // Nom unique construit à la main plutôt que tempnam() : celui-ci crée un fichier SANS
        // extension, et y ajouter « .csv » en laisserait un derrière à chaque test.
        $path = sys_get_temp_dir() . '/ancres-' . uniqid('', true) . '.csv';
        file_put_contents($path, $content);

        $this->files[] = $path;

        return $path;
    }
}
