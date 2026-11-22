<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Tests\Unit\Seed;

use Ox\Cli\Seed\Decor\ArrayCoding;
use Ox\Cli\Seed\Decor\FrenchHospitalDecor;
use Ox\Cli\Seed\Decor\SeedDecor;
use Ox\Cli\Seed\Decor\SurgicalClinicDecor;
use Ox\Cli\Seed\SeedException;
use PHPUnit\Framework\TestCase;

/**
 * LA CORRESPONDANCE SPÉCIALITÉ → CODES, ET CE QUI PEUT SILENCIEUSEMENT LA TROUER.
 *
 * Le codage est une table écrite à la main, indexée par LIBELLÉ D'ACTE. C'est ce qui permet à deux
 * décors français de la partager, et c'est aussi sa fragilité : renommer un acte dans un catalogue le
 * décroche de son code sans que rien ne le signale — l'intervention serait écrite sans cotation, et il
 * faudrait lire le rapport pour s'en apercevoir. Le premier test est là pour ça, et il vaut pour TOUS
 * les décors, y compris ceux qui n'existent pas encore.
 *
 * Ce qui est vérifié ensuite tient à trois exigences que le socle n'impose pas et que le jeu de données
 * doit pourtant respecter :
 *
 * 1. la FORME des codes. Un code CIM-10 ponctué passe le contrôle du socle et se fait vider ensuite,
 *    sans erreur visible en ligne de commande ;
 * 2. la COUVERTURE. Une unité sans diagnostic déclaré produit des séjours sans DP ;
 * 3. la FAISABILITÉ EN JOURNÉE. Une spécialité dont aucun acte n'est ambulatoire rend la contrainte de
 *    type de séjour inopérante, par repli sur la liste complète.
 *
 * Aucune base de données n'est nécessaire : la conformité des codes AU DICTIONNAIRE est vérifiée
 * ailleurs, par SeedCodingDictionaryTest.
 */
class CodingTest extends TestCase
{
    /**
     * @return SeedDecor[]
     */
    private function decors(): array
    {
        return [new FrenchHospitalDecor(), new SurgicalClinicDecor()];
    }

    /**
     * LE TEST QUI COMPTE : aucun acte d'aucun décor ne doit être décroché de son code.
     *
     * @throws SeedException
     */
    public function testEveryProcedureOfEveryDecorIsCoded(): void
    {
        foreach ($this->decors() as $decor) {
            $coding = $decor->coding();
            $this->assertNotNull($coding, "Le décor '{$decor->name()}' doit coder ses actes");

            foreach ($this->proceduresOf($decor) as $speciality => $procedures) {
                foreach ($procedures as $procedure) {
                    $this->assertNotNull(
                        $coding->ccamFor($procedure),
                        "« {$procedure} » ({$speciality}, décor '{$decor->name()}') n'a pas de code CCAM"
                    );

                    $this->assertNotSame(
                        [],
                        $coding->diagnosesForProcedure($procedure),
                        "« {$procedure} » n'a aucun diagnostic qui le motive : le séjour opéré prendrait"
                        . " un diagnostic de son unité, sans rapport avec l'acte"
                    );
                }
            }
        }
    }

    /**
     * Toute unité ouverte par un décor doit pouvoir porter un diagnostic — les chirurgicales aussi,
     * puisque tous leurs séjours ne sont pas opérés.
     *
     * @throws SeedException
     */
    public function testEveryUnitOfEveryDecorHasDiagnoses(): void
    {
        foreach ($this->decors() as $decor) {
            $coding    = $decor->coding();
            $catalogue = $decor->catalogue();

            foreach ($catalogue->first($catalogue->count()) as $entry) {
                $this->assertNotSame(
                    [],
                    $coding->diagnosesFor($entry['slug']),
                    "L'unité '{$entry['slug']}' du décor '{$decor->name()}' n'a aucun diagnostic"
                );
            }
        }
    }

    /**
     * Une spécialité chirurgicale sans acte ambulatoire annule la contrainte de type de séjour : le
     * builder se replie sur la liste complète, et une gastrectomie repart en hôpital de jour.
     *
     * @throws SeedException
     */
    public function testEverySurgicalSpecialityHasADayCaseProcedure(): void
    {
        foreach ($this->decors() as $decor) {
            $coding = $decor->coding();

            foreach ($this->proceduresOf($decor) as $speciality => $procedures) {
                $day_case = [];
                foreach ($procedures as $procedure) {
                    if ($coding->isAmbulatory($procedure)) {
                        $day_case[] = $procedure;
                    }
                }

                $this->assertNotSame(
                    [],
                    $day_case,
                    "Aucun acte de '{$speciality}' (décor '{$decor->name()}') n'est faisable en journée :"
                    . ' ses séjours ambulatoires seraient opérés de n\'importe quoi'
                );
            }
        }
    }

    /**
     * Les actes lourds ne doivent PAS être déclarés ambulatoires. Quelques cas incontestables suffisent
     * à protéger la liste d'un ajout distrait.
     *
     * @throws SeedException
     */
    public function testHeavyProceduresAreNotDayCase(): void
    {
        $coding = (new FrenchHospitalDecor())->coding();

        $heavy = [
            'Gastrectomie partielle',
            'Colectomie droite',
            'Prothèse totale de hanche',
            'Prothèse totale de genou',
            'Césarienne',
            'Lobectomie pulmonaire',
            "Cure d'anévrisme de l'aorte",
            'Sleeve gastrectomie',
            'Exérèse de méningiome',
        ];

        foreach ($heavy as $procedure) {
            $this->assertFalse(
                $coding->isAmbulatory($procedure),
                "« {$procedure} » ne se fait pas en hospitalisation de jour"
            );
        }
    }

    /**
     * LE PIÈGE DE LA FORME CIM-10 : `J18.9` passe le contrôle du socle — son motif accepte le point —
     * puis `CSejour::check()` ne trouve pas le code et VIDE le champ, en n'écrivant l'avertissement que
     * dans une file de messages d'interface que personne ne lit en ligne de commande. Le contrôle doit
     * donc avoir lieu à la construction du décor.
     */
    public function testAMalformedCodeIsRefused(): void
    {
        $cases = [
            'un code CCAM trop court'          => [['Acte' => 'NEKA20'], [], [], '/CCAM mal formé/'],
            'un code CCAM en minuscules'       => [['Acte' => 'neka020'], [], [], '/CCAM mal formé/'],
            'un code CIM-10 avec son point'    => [[], ['medecine' => ['J18.9']], [], '/sans point/'],
            'un code CIM-10 sans chiffre'      => [[], ['medecine' => ['J']], [], '/mal formé/'],
            'une liste de diagnostics vide'    => [[], ['medecine' => []], [], '/vide/'],
            'un diagnostic d\'acte ponctué'    => [[], [], ['Acte' => ['M16.0']], '/sans point/'],
        ];

        foreach ($cases as $label => [$ccam, $diagnoses, $procedure_diagnoses, $pattern]) {
            try {
                new ArrayCoding($ccam, $diagnoses, $procedure_diagnoses);
                $this->fail("Accepté alors qu'il fallait refuser : {$label}");
            } catch (SeedException $e) {
                $this->assertMatchesRegularExpression($pattern, $e->getMessage(), $label);
            }
        }
    }

    /**
     * Un décor peut légitimement ne pas coder, et un codage qui ne se prononce pas sur l'ambulatoire ne
     * doit pas bloquer les séjours de jour.
     *
     * @throws SeedException
     */
    public function testACodingMayStaySilent(): void
    {
        $coding = new ArrayCoding();

        $this->assertNull($coding->ccamFor('Acte inconnu'));
        $this->assertSame([], $coding->diagnosesFor('medecine'));
        $this->assertSame([], $coding->diagnosesForProcedure('Acte inconnu'));
        $this->assertTrue(
            $coding->isAmbulatory('Acte inconnu'),
            "Un codage muet sur l'ambulatoire laisse tout passer, il ne bloque pas tout"
        );
    }

    /**
     * Actes de chaque spécialité chirurgicale d'un décor, sans dépendre du nombre d'unités qu'un profil
     * ouvre : le catalogue entier est parcouru.
     *
     * @return array<string, string[]>
     * @throws SeedException
     */
    private function proceduresOf(SeedDecor $decor): array
    {
        $catalogue  = $decor->catalogue();
        $procedures = [];

        foreach ($catalogue->first($catalogue->count()) as $entry) {
            if (!$entry['surgical']) {
                continue;
            }

            $procedures[$entry['slug']] = $catalogue->procedures($entry['slug']);
        }

        return $procedures;
    }
}
