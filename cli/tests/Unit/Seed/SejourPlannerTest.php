<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Tests\Unit\Seed;

use Ox\Cli\Seed\CollectingWriter;
use Ox\Cli\Seed\SeedContext;
use Ox\Cli\Seed\SeedException;
use Ox\Cli\Seed\SeedProfile;
use Ox\Cli\Seed\SeedRandom;
use Ox\Cli\Seed\SejourPlan;
use Ox\Cli\Seed\SejourPlanner;
use Ox\Cli\Seed\Specialities;
use Ox\Core\CMbDT;
use PHPUnit\Framework\TestCase;

/**
 * Le cycle de vie temporel des séjours, vérifié SANS base.
 *
 * C'est tout l'intérêt d'avoir séparé la décision de l'écriture : les invariants 5 (un
 * ambulatoire entre et sort le même jour) et 6 (l'occupation instantanée tombe dans la bande
 * visée) portent sur des dates. Les vérifier ici coûte quelques millisecondes, alors que les
 * vérifier après écriture coûterait plusieurs minutes de suite d'intégration.
 */
class SejourPlannerTest extends TestCase
{
    private const NOW = '2026-06-15 09:00:00';

    /**
     * Topologie du profil `small` : six unités et soixante lits, réparties comme le fait
     * SeedProfile::bedsPerService() — les unités d'accueil des urgences reçoivent ce que leur flux
     * exige (trois lits), le reste se partage à parts égales.
     *
     * Les TROIS familles sont représentées, et c'est nécessaire : chacune a sa bande d'occupation,
     * ses durées de séjour et ses règles d'admission, et une topologie qui n'en aurait que deux ne
     * vérifierait rien de la troisième.
     */
    private const SERVICES = [
        'urgences'            => ['beds' => 2, 'intensive' => false, 'surgical' => false, 'emergency' => true],
        'uhcd'                => ['beds' => 1, 'intensive' => false, 'surgical' => false, 'emergency' => true],
        'medecine-interne'    => ['beds' => 15, 'intensive' => false, 'surgical' => false, 'emergency' => false],
        'chirurgie-viscerale' => ['beds' => 14, 'intensive' => false, 'surgical' => true, 'emergency' => false],
        'cardiologie'         => ['beds' => 14, 'intensive' => false, 'surgical' => false, 'emergency' => false],
        'reanimation'         => ['beds' => 14, 'intensive' => true, 'surgical' => false, 'emergency' => false],
    ];

    public function testItPlansExactlyTheRequestedNumberOfStays(): void
    {
        // 1556 séjours : ce n'est pas une constante du profil mais ce que 60 lits sur trois mois
        // exigent pour tenir l'occupation visée (cf. SeedProfile::requiredSejours()).
        $this->assertCount(1556, $this->plan(), 'Le profil small déduit 1556 séjours');
    }

    /**
     * Les soins continus sont exclus du décompte : la réanimation suit une logique d'occupation
     * différente et fausserait la bande visée.
     */
    public function testWardBedsExcludeIntensiveCareAndEmergencyBoxes(): void
    {
        $planner = $this->planner();

        $this->assertSame(43, $planner->wardBedCount(), '15 + 14 + 14');
        $this->assertSame(14, $planner->icuBedCount());
        $this->assertSame(3, $planner->emergencyBedCount());
    }

    public function testCurrentCountIsCalibratedOnCapacityNotOnTheStateMix(): void
    {
        $planner = $this->planner();

        // 43 lits d'hospitalisation × 80 % (milieu de la bande 75-85) = 34 ; 14 lits de soins
        // continus × 65 % (milieu de 55-75) = 9 ; 3 lits d'urgence × 60 % (milieu de 50-70) = 2.
        // La répartition d'états du profil annonce 5 % des séjours : c'est bien la CAPACITÉ qui
        // commande, pas le mix.
        $this->assertSame(34, $planner->currentCount());
        $this->assertSame(9, $planner->icuCurrentCount());
        $this->assertSame(2, $planner->emergencyCurrentCount());

        $current = 0;
        foreach ($planner->plan() as $plan) {
            if ($plan->isCurrent()) {
                $current++;
            }
        }

        $this->assertSame(45, $current, 'Les TROIS bandes sont calibrées, pas seulement celle des lits');
    }

    /**
     * Invariant 6, mesuré sur les plans : l'occupation instantanée des lits d'hospitalisation
     * complète doit tomber dans la bande annoncée. C'est le premier chiffre qu'un formateur
     * regarde.
     */
    public function testInstantaneousOccupancyFallsInTheTargetBand(): void
    {
        $planner   = $this->planner();
        $ward_beds = $planner->wardBedCount();

        $occupied = 0;
        foreach ($planner->plan() as $plan) {
            if ($plan->occupiesBedAt(self::NOW) && !self::isIntensive($plan->service())) {
                $occupied++;
            }
        }

        $rate = 100 * $occupied / $ward_beds;

        [$min, $max] = SeedProfile::named('small')->occupancyBand();

        $this->assertGreaterThanOrEqual($min, $rate, "Occupation de {$rate} %, sous la bande");
        $this->assertLessThanOrEqual($max, $rate, "Occupation de {$rate} %, au-dessus de la bande");
    }

    /**
     * Les soins continus ont LEUR PROPRE bande, plus basse.
     *
     * Ils n'en avaient aucune : aucun séjour ne leur était attribué, et la réanimation restait
     * entièrement vide — sur le profil medium, 40 lits inertes, soit un cinquième de
     * l'établissement, et c'était le premier écran vide qu'on rencontrait en ouvrant le plan des
     * lits.
     */
    public function testIntensiveCareOccupancyFallsInItsOwnBand(): void
    {
        $planner  = $this->planner();
        $icu_beds = $planner->icuBedCount();

        $this->assertSame(14, $icu_beds, 'La réanimation du profil small compte 14 lits');

        $occupied = 0;
        foreach ($planner->plan() as $plan) {
            if ($plan->occupiesBedAt(self::NOW) && self::isIntensive($plan->service())) {
                $occupied++;
            }
        }

        $this->assertGreaterThan(0, $occupied, 'Une réanimation vide est le premier écran vide du SIH');

        $rate        = 100 * $occupied / $icu_beds;
        [$min, $max] = SeedProfile::named('small')->icuOccupancyBand();

        $this->assertGreaterThanOrEqual($min, $rate, "Soins continus à {$rate} %, sous la bande");
        $this->assertLessThanOrEqual($max, $rate, "Soins continus à {$rate} %, au-dessus de la bande");
    }

    /**
     * La répartition des séjours en cours suit les LITS de chaque service.
     *
     * Un tirage uniforme donnait des écarts visibles d'un service à l'autre — mesurés entre 50 %
     * et 95 % sur un même établissement — parce que rien ne rattachait le nombre de séjours d'un
     * service à sa capacité. Un tableau d'occupation où un service est plein et son voisin à
     * moitié vide ne s'explique par rien.
     */
    public function testCurrentStaysAreSpreadInProportionToBeds(): void
    {
        $occupied = [];
        foreach ($this->plan() as $plan) {
            if ($plan->occupiesBedAt(self::NOW)) {
                $occupied[$plan->service()] = ($occupied[$plan->service()] ?? 0) + 1;
            }
        }

        // Les trois services d'hospitalisation ont 15, 14 et 14 lits : ils doivent porter des
        // effectifs quasi identiques, à l'arrondi près (34 séjours pour 43 lits).
        foreach (['medecine-interne', 'chirurgie-viscerale', 'cardiologie'] as $slug) {
            $this->assertArrayHasKey($slug, $occupied, "Le service '{$slug}' ne doit pas rester vide");
            $this->assertLessThanOrEqual(
                1,
                abs($occupied[$slug] - 11),
                "Le service '{$slug}' porte {$occupied[$slug]} séjours au lieu de 11 (34 / 43 lits)"
            );
        }

        // Et les unités d'accueil ne sont pas vides non plus : c'était le cas quand aucun séjour
        // urgent n'était hébergé.
        $this->assertGreaterThan(
            0,
            ($occupied['urgences'] ?? 0) + ($occupied['uhcd'] ?? 0),
            "Les boxes d'urgence et l'UHCD doivent porter des patients"
        );
    }

    /**
     * Seuls les séjours qui occupent un lit nommé peuvent être « en cours » au sens de
     * l'occupation : sans cette règle, la mesure ci-dessus n'aurait aucun sens.
     */
    public function testOnlyBedHoldingStaysCanBeCurrent(): void
    {
        foreach ($this->plan() as $plan) {
            if ($plan->isCurrent()) {
                $this->assertTrue(
                    $plan->needsBed(),
                    "Un séjour de type '{$plan->type()}' ne peut pas être en cours sans occuper de lit"
                );
            }
        }
    }

    public function testTypeMixIsRespectedExactly(): void
    {
        $counts = [];
        foreach ($this->plan() as $plan) {
            $counts[$plan->type()] = ($counts[$plan->type()] ?? 0) + 1;
        }

        // Mix 40/35/10/8/4/3 : les comptes doivent tomber juste, au reste d'arrondi près.
        $total = SeedProfile::named('small')->sejours();
        $this->assertSame($total, array_sum($counts));

        foreach (SeedProfile::named('small')->sejourTypeMix() as $type => $percent) {
            $expected = $total * $percent / 100;
            $this->assertLessThanOrEqual(
                1,
                abs($counts[$type] - $expected),
                "Type '{$type}' : {$counts[$type]} séjours au lieu de {$expected}"
            );
        }
    }

    /**
     * Invariant 5 : un ambulatoire entre et sort le même jour calendaire, et n'occupe pas de lit.
     */
    public function testAmbulatoryStaysBeginAndEndOnTheSameDay(): void
    {
        $seen = 0;

        foreach ($this->plan() as $plan) {
            if ($plan->type() !== 'ambu') {
                continue;
            }

            $seen++;

            $this->assertFalse($plan->needsBed(), "Un ambulatoire n'occupe pas de lit nommé");
            $this->assertTrue($plan->createsAffectation(), "Un ambulatoire produit une affectation de service");

            $this->assertSame(
                substr($plan->entreePrevue(), 0, 10),
                substr($plan->sortiePrevue(), 0, 10),
                'Entrée et sortie prévues le même jour'
            );
            $this->assertLessThan(
                $plan->sortiePrevue(),
                $plan->entreePrevue(),
                'La sortie doit suivre l\'entrée'
            );

            if ($plan->entreeReelle() !== null && $plan->sortieReelle() !== null) {
                $this->assertSame(
                    substr($plan->entreeReelle(), 0, 10),
                    substr($plan->sortieReelle(), 0, 10),
                    'Entrée et sortie réelles le même jour'
                );
            }
        }

        $this->assertGreaterThan(100, $seen, 'Le mix doit produire une majorité relative d\'ambulatoires');
    }

    public function testOutpatientTypesProduceNoAffectation(): void
    {
        $seen = 0;

        foreach ($this->plan() as $plan) {
            if (!in_array($plan->type(), SejourPlan::NO_AFFECTATION_TYPES, true)) {
                continue;
            }

            $seen++;
            $this->assertFalse($plan->createsAffectation(), "Un '{$plan->type()}' n'est pas hébergé");
            $this->assertFalse($plan->needsBed());
        }

        $this->assertGreaterThan(0, $seen);
    }

    public function testClosedStaysAreEntirelyInThePast(): void
    {
        $seen = 0;

        foreach ($this->plan() as $plan) {
            if (!$plan->isClosed()) {
                continue;
            }

            $seen++;
            $this->assertNotNull($plan->entreeReelle());
            $this->assertNotNull($plan->sortieReelle());
            $this->assertLessThan(self::NOW, $plan->entreeReelle(), 'Entrée réelle dans le passé');
            $this->assertLessThan(self::NOW, $plan->sortieReelle(), 'Sortie réelle dans le passé');
            $this->assertLessThanOrEqual($plan->sortieReelle(), $plan->entreeReelle());
            $this->assertNotNull($plan->modeSortie(), 'Un séjour clos a un mode de sortie');
        }

        $this->assertGreaterThan(200, $seen);
    }

    /**
     * Un séjour en cours est ce qui rend l'application vivante : entré, pas encore sorti, et son
     * lit réservé jusqu'à la sortie prévue.
     */
    public function testCurrentStaysAreOpenEndedAndStillRunning(): void
    {
        foreach ($this->plan() as $plan) {
            if (!$plan->isCurrent()) {
                continue;
            }

            $this->assertNotNull($plan->entreeReelle());
            $this->assertNull($plan->sortieReelle(), 'Un séjour en cours n\'a pas de sortie réelle');
            $this->assertNull($plan->modeSortie(), 'Ni de mode de sortie');
            $this->assertLessThan(self::NOW, $plan->entreeReelle(), 'Déjà entré');
            $this->assertGreaterThan(self::NOW, $plan->sortiePrevue(), 'Sortie prévue devant nous');

            $bounds = $plan->stayBounds();
            $this->assertNotNull($bounds);
            $this->assertTrue($plan->occupiesBedAt(self::NOW), 'Occupe donc un lit maintenant');
        }
    }

    public function testPlannedStaysHaveNoRealDatesAndLieAhead(): void
    {
        $seen = 0;

        foreach ($this->plan() as $plan) {
            if (!$plan->isPlanned()) {
                continue;
            }

            $seen++;
            $this->assertNull($plan->entreeReelle());
            $this->assertNull($plan->sortieReelle());
            $this->assertNull($plan->stayBounds(), 'Un séjour planifié n\'a pas commencé');
            $this->assertFalse($plan->occupiesBedAt(self::NOW), 'Et n\'occupe aucun lit');
            $this->assertGreaterThan(self::NOW, $plan->entreePrevue(), 'Entrée prévue future');
            $this->assertGreaterThanOrEqual($plan->entreePrevue(), $plan->sortiePrevue());
        }

        $this->assertGreaterThan(20, $seen, 'Le profil annonce environ 10 % de planifiés');
    }

    public function testPlannedStaysStayWithinTheForwardHorizon(): void
    {
        // La fenêtre de small est de 3 mois ; l'horizon futur en vaut le quart, soit environ
        // un mois. Un séjour planifié à deux ans n'aurait aucun sens opérationnel.
        $limit = '2026-07-31 23:59:59';

        foreach ($this->plan() as $plan) {
            if ($plan->isPlanned()) {
                $this->assertLessThan($limit, $plan->entreePrevue());
            }
        }
    }

    public function testStaysStayWithinThePastWindow(): void
    {
        // Fenêtre de 3 mois avant l'instant de référence.
        $start = '2026-03-15 00:00:00';

        foreach ($this->plan() as $plan) {
            if ($plan->entreeReelle() !== null) {
                $this->assertGreaterThanOrEqual($start, $plan->entreeReelle());
            }
        }
    }

    public function testEmergencyStaysComeFromTheEmergencyRoom(): void
    {
        $seen = 0;

        foreach ($this->plan() as $plan) {
            if ($plan->type() !== 'urg') {
                continue;
            }

            $seen++;
            $this->assertSame('7', $plan->modeEntree(), 'Un séjour urgent entre par les urgences');
        }

        $this->assertGreaterThan(0, $seen);
    }

    /**
     * Seuls les séjours EN COURS peuvent être en soins continus, parce que ceux-là sont attribués
     * par quota. Un tirage ordinaire n'y envoie personne : l'occupation de ces unités est calibrée,
     * pas tirée.
     */
    public function testEmergencyStaysAreHostedOnlyInEmergencyUnits(): void
    {
        $seen = 0;

        foreach ($this->plan() as $plan) {
            $emergency = in_array($plan->service(), ['urgences', 'uhcd'], true);

            if ($plan->type() === 'urg') {
                $seen++;
                $this->assertTrue(
                    $emergency,
                    "Un séjour urgent hébergé en '{$plan->service()}' : le socle le refuse"
                );
                $this->assertTrue($plan->needsBed(), 'Un séjour urgent occupe un box');
                $this->assertTrue($plan->createsAffectation(), 'Et produit donc une affectation');
            } else {
                $this->assertFalse(
                    $emergency,
                    "Un séjour de type '{$plan->type()}' n'a rien à faire dans un box d'urgence"
                );
            }
        }

        $this->assertGreaterThan(0, $seen, 'Le mix annonce 10 % de séjours urgents');
    }

    /**
     * Une urgence n'attend pas les heures ouvrables. Sans cela, un tableau de garde n'aurait jamais
     * rien à montrer la nuit.
     */
    public function testEmergencyArrivalsHappenAtAnyHour(): void
    {
        $hours = [];

        foreach ($this->plan() as $plan) {
            if ($plan->type() !== 'urg') {
                continue;
            }

            $hours[(int)substr($plan->entreePrevue(), 11, 2)] = true;
        }

        // Au moins une arrivée entre minuit et six heures, ce qu'un tirage entre 7 h et 20 h ne
        // pouvait pas produire.
        $night = array_intersect_key($hours, array_flip([0, 1, 2, 3, 4, 5, 6]));

        $this->assertNotEmpty($night, 'Aucune arrivée nocturne aux urgences');
        $this->assertGreaterThan(12, count($hours), 'Les arrivées doivent couvrir la journée entière');
    }

    public function testOnlyCalibratedStaysLandInIntensiveCare(): void
    {
        $in_icu = 0;

        foreach ($this->plan() as $plan) {
            if (!self::isIntensive($plan->service())) {
                continue;
            }

            $in_icu++;
            $this->assertTrue(
                $plan->isCurrent(),
                'Les admissions tirées au hasard ne vont pas en soins continus'
            );
        }

        $this->assertGreaterThan(0, $in_icu);
    }

    /**
     * Une intervention tombe TOUJOURS un jour où le bloc opère.
     *
     * C'est l'inversion de causalité du planificateur, et elle corrige une perte sèche : le
     * premier jet plaçait le séjour, puis le builder cherchait une plage le jour de l'entrée.
     * 156 interventions sur 474 tombaient un week-end ou hors période programmée et
     * disparaissaient — un tiers du programme opératoire, silencieusement perdu.
     */
    public function testEveryOperationLandsOnAnOperatingDay(): void
    {
        $capacity   = self::operatingDays();
        $operations = 0;
        $per_day    = [];

        foreach ($this->plan() as $plan) {
            if (!$plan->withOperation()) {
                continue;
            }

            $operations++;
            $day = $plan->operationDay();

            $this->assertNotNull($day, 'Une intervention sans jour opératoire serait perdue');
            $this->assertArrayHasKey($day, $capacity, "Le {$day}, aucun bloc n'ouvre");
            $this->assertArrayHasKey(
                (string)$plan->operationPool(),
                $capacity[$day],
                "Le {$day}, la réserve « {$plan->operationPool()} » n'a pas de vacation"
            );

            $per_day[$day][(string)$plan->operationPool()] =
                ($per_day[$day][(string)$plan->operationPool()] ?? 0) + 1;

            // Et ce jour doit tomber DANS le séjour, sinon l'intervention n'a pas lieu pendant
            // l'hospitalisation qui la porte.
            $this->assertGreaterThanOrEqual(substr($plan->entreePrevue(), 0, 10), $day);
            $this->assertLessThanOrEqual(substr($plan->sortiePrevue(), 0, 10), $day);
        }

        $this->assertGreaterThan(0, $operations, 'Le bloc doit avoir du travail');

        // Et jamais plus d'interventions un jour donné que le bloc n'en peut absorber : sans ce
        // compte, plusieurs séjours se donnaient rendez-vous le même jour et les interventions en
        // excès étaient perdues à l'écriture.
        foreach ($per_day as $day => $pools) {
            foreach ($pools as $pool => $count) {
                $this->assertLessThanOrEqual(
                    2,
                    $count,
                    "Le {$day}, réserve « {$pool} » : {$count} interventions pour 2 places"
                );
            }
        }
    }

    /**
     * Le calage sur les jours opératoires ne doit pas faire sortir un séjour de son état : un
     * séjour clos reste dans le passé, un séjour planifié reste devant nous.
     */
    public function testShiftingAStayOntoAnOperatingDayPreservesItsState(): void
    {
        foreach ($this->plan() as $plan) {
            if (!$plan->withOperation()) {
                continue;
            }

            if ($plan->isClosed()) {
                $this->assertLessThan(self::NOW, $plan->sortieReelle(), 'Un séjour clos reste passé');
            }

            if ($plan->isPlanned()) {
                $this->assertGreaterThan(self::NOW, $plan->entreePrevue(), 'Un planifié reste futur');
            }
        }
    }

    /**
     * Un séjour de soins continus est plus court qu'une hospitalisation ordinaire : c'est ce qui
     * distingue un passage en réanimation d'un séjour de médecine.
     */
    public function testIntensiveCareStaysAreShorter(): void
    {
        $seen = 0;

        foreach ($this->plan() as $plan) {
            if (!self::isIntensive($plan->service())) {
                continue;
            }

            $seen++;
            $days = (int)CMbDT::daysRelative(
                substr($plan->entreePrevue(), 0, 10),
                substr($plan->sortiePrevue(), 0, 10)
            );

            $this->assertGreaterThanOrEqual(2, $days);
            $this->assertLessThanOrEqual(9, $days, 'Une réanimation ne garde pas un patient douze jours');
        }

        $this->assertGreaterThan(0, $seen);
    }

    /**
     * Deux chemins vers le bloc, et deux seulement : le programme d'une spécialité chirurgicale, ou
     * la garde du bloc d'urgence. Un séjour de médecine ne se retrouve pas au bloc.
     */
    public function testOperationsComeEitherFromSurgeryOrFromTheEmergencyRoom(): void
    {
        $scheduled = 0;
        $emergency = 0;

        foreach ($this->plan() as $plan) {
            if (!$plan->withOperation()) {
                continue;
            }

            if ($plan->type() === 'urg') {
                $emergency++;

                $this->assertTrue(
                    $plan->operationIsEmergency(),
                    "Une intervention en urgence s'inscrit dans la garde, pas dans le programme"
                );
                $this->assertSame(Specialities::EMERGENCY_THEATRE, $plan->operationPool());

                continue;
            }

            $scheduled++;

            $this->assertSame(
                'chirurgie-viscerale',
                $plan->service(),
                'Seul service chirurgical du profil small'
            );
            $this->assertContains($plan->type(), ['comp', 'ambu']);
            $this->assertFalse($plan->operationIsEmergency());
            $this->assertSame(
                $plan->service(),
                $plan->operationPool(),
                "Une intervention programmée s'inscrit dans la vacation de SA spécialité"
            );
        }

        $this->assertGreaterThan(0, $scheduled, 'Le bloc programmé doit avoir du travail');
        $this->assertGreaterThan(0, $emergency, "Le bloc d'urgence aussi");
    }

    public function testMutationsProduceTwoSegments(): void
    {
        $with_mutation = 0;

        foreach ($this->plan() as $plan) {
            $this->assertGreaterThanOrEqual(1, $plan->segments());
            $this->assertLessThanOrEqual(2, $plan->segments());

            if ($plan->segments() === 2) {
                $with_mutation++;
                $this->assertTrue($plan->needsBed(), 'Une mutation suppose un lit');
            }
        }

        $this->assertGreaterThan(0, $with_mutation, 'Une mutation de service est un cas pédagogique utile');
    }

    /**
     * Aucune date produite ne doit porter de secondes.
     *
     * `CSejour` tronque ses dates à la minute en base. Une date de plan qui garde des secondes
     * ressort donc différente de ce que la base contient, et l'affectation calée dessus finit
     * quelques secondes APRÈS la sortie du séjour — l'invariant 3 tombe. C'est arrivé : six
     * affectations hors bornes sur un profil medium, parce que les dates de repli héritaient des
     * secondes de l'horloge (« maintenant + 1 jour » à 20:36:17).
     *
     * Les autres tests figent l'instant de référence à une seconde ronde, ce qui rendait ce
     * défaut STRUCTURELLEMENT invisible. D'où l'instant volontairement bancal ci-dessous.
     */
    public function testNoPlanDateCarriesSeconds(): void
    {
        $context = new SeedContext(
            SeedProfile::named('small'),
            new SeedRandom(4242),
            new CollectingWriter(false),
            '2026-06-15 09:37:42'
        );

        $plans = (new SejourPlanner($context, self::SERVICES, self::operatingDays()))->plan();
        $this->assertNotEmpty($plans);

        foreach ($plans as $plan) {
            $dates = [
                'entrée prévue' => $plan->entreePrevue(),
                'sortie prévue' => $plan->sortiePrevue(),
                'entrée réelle' => $plan->entreeReelle(),
                'sortie réelle' => $plan->sortieReelle(),
            ];

            foreach ($dates as $label => $date) {
                if ($date === null) {
                    continue;
                }

                $this->assertSame(
                    '00',
                    substr($date, 17, 2),
                    "La {$label} '{$date}' porte des secondes : la base les tronquera et les"
                    . ' affectations calées dessus sortiront des bornes du séjour'
                );
            }
        }
    }

    public function testSameSeedProducesTheSamePlans(): void
    {
        $describe = function (array $plans): array {
            $rows = [];
            foreach ($plans as $plan) {
                $rows[] = implode('|', [
                    $plan->type(),
                    $plan->state(),
                    $plan->service(),
                    $plan->entreePrevue(),
                    $plan->sortiePrevue(),
                    (string)$plan->entreeReelle(),
                    (string)$plan->sortieReelle(),
                    (string)$plan->segments(),
                    $plan->withOperation() ? 'op' : '-',
                ]);
            }

            return $rows;
        };

        $this->assertSame($describe($this->plan(77)), $describe($this->plan(77)));
        $this->assertNotSame($describe($this->plan(77)), $describe($this->plan(78)));
    }

    /**
     * Un profil sur-dimensionné en lits par rapport aux séjours ne doit pas produire un jeu de
     * données à l'occupation absurde : il doit refuser, avec la consigne pour s'en sortir.
     */
    public function testAnImpossibleCapacityIsRefused(): void
    {
        $context = new SeedContext(
            SeedProfile::named('small', ['sejours' => 10]),
            new SeedRandom(1),
            new CollectingWriter(false),
            self::NOW
        );

        $planner = new SejourPlanner($context, self::SERVICES);

        $this->expectException(SeedException::class);
        $this->expectExceptionMessageMatches('/Capacité incohérente/');
        $planner->plan();
    }

    public function testNoServiceIsRefused(): void
    {
        $this->expectException(SeedException::class);
        new SejourPlanner($this->context(), []);
    }

    /**
     * @return SejourPlan[]
     * @throws SeedException
     */
    private function plan(int $seed = 4242): array
    {
        return $this->planner($seed)->plan();
    }

    /**
     * @throws SeedException
     */
    private function planner(int $seed = 4242): SejourPlanner
    {
        return new SejourPlanner($this->context($seed), self::SERVICES, self::operatingDays());
    }

    private static function isIntensive(string $slug): bool
    {
        return (bool)(self::SERVICES[$slug]['intensive'] ?? false);
    }

    /**
     * Capacité opératoire des jours ouvrés de la fenêtre du profil `small`, horizon programmé
     * compris : c'est ce que BlocBuilder produit comme plages, et donc ce que le planificateur doit
     * recevoir.
     *
     * La capacité est détaillée PAR RÉSERVE DE VACATIONS : celles du bloc programmé appartiennent à
     * une spécialité et n'existent que les jours ouvrés, celle du bloc d'urgence appartient à
     * l'équipe de garde et existe TOUS LES JOURS — c'est ce qui permet d'opérer un dimanche.
     *
     * @return array<string, array<string, int>>
     */
    private static function operatingDays(): array
    {
        $day  = '2026-03-15';
        $end  = '2026-07-15';
        $days = [];

        while (strcmp($day, $end) <= 0) {
            $days[$day] = [Specialities::EMERGENCY_THEATRE => 2];

            if ((int)CMbDT::format($day, '%u') <= 5) {
                $days[$day]['chirurgie-viscerale'] = 2;
            }

            $day = CMbDT::date('+1 day', $day);
        }

        return $days;
    }

    /**
     * @throws SeedException
     */
    private function context(int $seed = 4242): SeedContext
    {
        return new SeedContext(
            SeedProfile::named('small'),
            new SeedRandom($seed),
            new CollectingWriter(false),
            self::NOW
        );
    }
}
