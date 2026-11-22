<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Tests\Unit\Seed;

use Ox\Cli\Seed\CollectingWriter;
use Ox\Cli\Seed\Decor\ArrayCatalogue;
use Ox\Cli\Seed\Decor\ClinicalMix;
use Ox\Cli\Seed\Decor\DecorRegistry;
use Ox\Cli\Seed\Decor\FrenchHospitalDecor;
use Ox\Cli\Seed\Decor\SeedDecor;
use Ox\Cli\Seed\Decor\SurgicalClinicDecor;
use Ox\Cli\Seed\SeedContext;
use Ox\Cli\Seed\SeedException;
use Ox\Cli\Seed\SeedProfile;
use Ox\Cli\Seed\SeedRandom;
use Ox\Cli\Seed\SejourPlan;
use Ox\Cli\Seed\SejourPlanner;
use PHPUnit\Framework\TestCase;

/**
 * L'API de décors, et le fait qu'elle serve à quelque chose.
 *
 * UN POINT D'EXTENSION À IMPLÉMENTATION UNIQUE N'EST PAS VÉRIFIÉ : il suffit qu'une seule dépendance
 * ait été oubliée derrière l'interface pour qu'un second décor s'effondre, et rien ne le dirait. Ces
 * tests comparent donc systématiquement DEUX décors — un centre hospitalier et une clinique
 * chirurgicale — sur ce que l'API prétend rendre variable.
 *
 * Ils vérifient aussi les deux choses qu'un décor ne doit PAS pouvoir faire : produire un mix
 * incohérent, et annoncer des séjours qu'aucune de ses unités ne pourrait héberger.
 *
 * Aucune base de données n'est nécessaire.
 */
class DecorTest extends TestCase
{
    private const NOW = '2026-06-15 09:00:00';

    /**
     * @throws SeedException
     */
    public function testTheRegistryExposesEveryDecor(): void
    {
        $names = DecorRegistry::names();

        $this->assertContains(FrenchHospitalDecor::NAME, $names);
        $this->assertContains(SurgicalClinicDecor::NAME, $names);
        $this->assertSame(FrenchHospitalDecor::NAME, DecorRegistry::DEFAULT_DECOR);

        foreach (DecorRegistry::describe() as $name => $description) {
            $this->assertNotSame('', $description, "Le décor '{$name}' doit se décrire");
            $this->assertSame($name, DecorRegistry::get($name)->name());
        }
    }

    public function testAnUnknownDecorIsRefused(): void
    {
        $this->expectException(SeedException::class);
        $this->expectExceptionMessageMatches('/Décor inconnu/');

        DecorRegistry::get('hopital-de-fiction');
    }

    /**
     * LE CŒUR DE L'AFFAIRE : deux décors, deux établissements réellement différents, un seul moteur.
     *
     * @throws SeedException
     */
    public function testTwoDecorsProduceTwoDifferentEstablishments(): void
    {
        $hospital = $this->plans(new FrenchHospitalDecor());
        $clinic   = $this->plans(new SurgicalClinicDecor());

        // Les unités ne sont pas les mêmes, et aucune n'est partagée.
        $this->assertSame(
            [],
            array_intersect($this->servicesOf($hospital), $this->servicesOf($clinic)),
            'Les deux décors ne doivent partager aucune unité'
        );

        // L'hôpital héberge des urgences, la clinique n'en a pas.
        $this->assertGreaterThan(0, $this->countType($hospital, 'urg'), "L'hôpital reçoit des urgences");
        $this->assertSame(0, $this->countType($clinic, 'urg'), "La clinique n'en reçoit aucune");

        // Et la clinique est dominée par l'ambulatoire, là où l'hôpital équilibre.
        $this->assertGreaterThan(
            $this->shareOfType($hospital, 'ambu'),
            $this->shareOfType($clinic, 'ambu'),
            "L'ambulatoire doit peser davantage dans une clinique chirurgicale"
        );
    }

    /**
     * Les séjours d'une clinique sont plus courts, et c'est le décor qui le dit — pas le moteur.
     *
     * @throws SeedException
     */
    public function testTheDecorDecidesHowLongAStayLasts(): void
    {
        $hospital = (new FrenchHospitalDecor())->clinicalMix();
        $clinic   = (new SurgicalClinicDecor())->clinicalMix();

        $this->assertSame([2, 12], $hospital->duration('comp'));
        $this->assertSame([1, 5], $clinic->duration('comp'));

        // Conséquence arithmétique, et c'est le point : à capacité et fenêtre égales, des séjours plus
        // courts en exigent DAVANTAGE pour tenir l'occupation. Le décor ne calcule pas ce nombre, il
        // découle des durées qu'il déclare.
        $long  = SeedProfile::named('small', [], new FrenchHospitalDecor());
        $short = SeedProfile::named('small', [], new SurgicalClinicDecor());

        $this->assertGreaterThan(
            $long->sejours(),
            $short->sejours(),
            'Des séjours plus courts imposent un flux plus élevé : la loi de Little ne se négocie pas'
        );
    }

    /**
     * Un décor ne peut pas annoncer des urgences sans unité pour les accueillir : le socle refuserait
     * l'affectation, et le jeu de données serait silencieusement troué.
     *
     * @throws SeedException
     */
    public function testADecorCannotPromiseStaysItCannotHost(): void
    {
        $services = [
            'chirurgie' => ['beds' => 20, 'intensive' => false, 'surgical' => true, 'emergency' => false],
            'medecine'  => ['beds' => 20, 'intensive' => false, 'surgical' => false, 'emergency' => false],
        ];

        // Le décor hospitalier annonce 10 % de séjours urgents ; cette topologie n'a aucune unité
        // d'accueil.
        $context = new SeedContext(
            SeedProfile::named('small', [], new FrenchHospitalDecor()),
            new SeedRandom(1),
            new CollectingWriter(false),
            self::NOW
        );

        $this->expectException(SeedException::class);
        $this->expectExceptionMessageMatches('/Décor incohérent/');

        (new SejourPlanner($context, $services, []))->plan();
    }

    /**
     * Les garde-fous de cohérence du mix, qui valent pour tout décor.
     */
    public function testAnIncoherentMixIsRefused(): void
    {
        $cases = [
            'un mix qui ne totalise pas cent'           => [['type_mix' => ['comp' => 50, 'ambu' => 30]], '/totalise 80/'],
            'un mix sans hospitalisation complète'      => [['type_mix' => ['ambu' => 100]], '/aucun séjour/'],
            'une bande d\'occupation inversée'          => [['occupancy_band' => [90, 40]], '/incohérente/'],
            'un paramètre inconnu'                      => [['occupancy' => [70, 80]], '/inconnu/'],
            'des actes trop longs pour leur vacation'   => [['operation_minutes' => [45, 200]], '/ne tiennent pas/'],
        ];

        foreach ($cases as $label => [$overrides, $pattern]) {
            try {
                new ClinicalMix($overrides);
                $this->fail("Accepté alors qu'il fallait refuser : {$label}");
            } catch (SeedException $e) {
                $this->assertMatchesRegularExpression($pattern, $e->getMessage(), $label);
            }
        }
    }

    /**
     * Un catalogue vide n'est pas un décor.
     */
    public function testAnEmptyCatalogueIsRefused(): void
    {
        $this->expectException(SeedException::class);
        $this->expectExceptionMessageMatches('/Catalogue vide/');

        new ArrayCatalogue([]);
    }

    /**
     * Ce qu'un décor n'a PAS à déclarer : le catalogue générique répond par défaut, de sorte qu'un
     * décor ne décrive que ses exceptions.
     *
     * @throws SeedException
     */
    public function testACatalogueOnlyNeedsToDeclareItsExceptions(): void
    {
        $catalogue = new ArrayCatalogue(
            [['slug' => 'medecine', 'service' => 'Médecine', 'fonction' => 'Médecine', 'surgical' => false, 'intensive' => false]]
        );

        $this->assertSame(1, $catalogue->count());
        $this->assertSame(['min_age' => 18, 'max_age' => 95, 'sex' => null], $catalogue->patientProfile('medecine'));
        $this->assertFalse($catalogue->isEmergency('medecine'));
        $this->assertFalse($catalogue->isPaediatric('medecine'));
        $this->assertSame([], $catalogue->serviceFlags('medecine'));
        $this->assertSame('Anesthésie', $catalogue->anaesthesiaFunction());
        $this->assertSame(
            ['surgical' => 0, 'ward' => 1, 'intensive' => 0, 'emergency' => 0, 'paediatric' => 0],
            $catalogue->counts(1)
        );
    }

    /**
     * Le déterminisme survit au décor : c'est la propriété que toute la mécanique protège, et elle ne
     * doit pas dépendre de qui fournit les données.
     *
     * @throws SeedException
     */
    public function testEachDecorIsReproducible(): void
    {
        foreach ([new FrenchHospitalDecor(), new SurgicalClinicDecor()] as $decor) {
            $first  = $this->describe($this->plans($decor));
            $second = $this->describe($this->plans($decor));

            $this->assertSame($first, $second, "Le décor '{$decor->name()}' doit être rejouable");
        }
    }

    /**
     * Plans produits par un décor, sur une échelle réduite mais cohérente.
     *
     * @return SejourPlan[]
     * @throws SeedException
     */
    private function plans(SeedDecor $decor): array
    {
        $profile = SeedProfile::named('small', ['beds' => 40, 'window' => 1], $decor);
        $context = new SeedContext($profile, new SeedRandom(4242), new CollectingWriter(false), self::NOW);

        $services  = [];
        $catalogue = $decor->catalogue();
        $beds      = $profile->bedsPerService($catalogue->first($profile->services()));

        foreach ($catalogue->first($profile->services()) as $spec) {
            $services[$spec['slug']] = [
                'beds'      => $beds[$spec['slug']],
                'intensive' => $spec['intensive'],
                'surgical'  => $spec['surgical'],
                'emergency' => $catalogue->isEmergency($spec['slug']),
            ];
        }

        return (new SejourPlanner($context, $services, []))->plan();
    }

    /**
     * @param SejourPlan[] $plans
     *
     * @return string[]
     */
    private function servicesOf(array $plans): array
    {
        $services = [];
        foreach ($plans as $plan) {
            $services[$plan->service()] = true;
        }

        return array_keys($services);
    }

    /**
     * @param SejourPlan[] $plans
     */
    private function countType(array $plans, string $type): int
    {
        $count = 0;
        foreach ($plans as $plan) {
            if ($plan->type() === $type) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param SejourPlan[] $plans
     */
    private function shareOfType(array $plans, string $type): float
    {
        return $plans === [] ? 0.0 : 100 * $this->countType($plans, $type) / count($plans);
    }

    /**
     * @param SejourPlan[] $plans
     *
     * @return string[]
     */
    private function describe(array $plans): array
    {
        $rows = [];
        foreach ($plans as $plan) {
            $rows[] = implode('|', [
                $plan->type(),
                $plan->state(),
                $plan->service(),
                $plan->entreePrevue(),
                $plan->sortiePrevue(),
                (string)$plan->operationDay(),
            ]);
        }

        return $rows;
    }
}
