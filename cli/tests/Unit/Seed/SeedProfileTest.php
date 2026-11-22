<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Tests\Unit\Seed;

use Ox\Cli\Seed\SeedException;
use Ox\Cli\Seed\SeedProfile;
use PHPUnit\Framework\TestCase;

/**
 * Aucune base de données n'est nécessaire.
 */
class SeedProfileTest extends TestCase
{
    /**
     * Un profil ne déclare que TROIS grandeurs : services, lits, fenêtre. Les séjours et les patients
     * en découlent, et c'est ce qui rend impossible d'écrire un profil physiquement incohérent.
     *
     * @throws SeedException
     */
    public function testProfilesAreTheAnnouncedScales(): void
    {
        $expected = [
            'small'  => ['services' => 6, 'beds' => 60, 'patients' => 622, 'sejours' => 1556, 'window' => 3],
            'medium' => ['services' => 12, 'beds' => 200, 'patients' => 8324, 'sejours' => 20809, 'window' => 12],
            'large'  => ['services' => 22, 'beds' => 600, 'patients' => 25130, 'sejours' => 62824, 'window' => 12],
            'xl'     => ['services' => 42, 'beds' => 1200, 'patients' => 100678, 'sejours' => 251694, 'window' => 24],
        ];

        $this->assertSame(array_keys($expected), SeedProfile::names());

        foreach ($expected as $name => $values) {
            $profile = SeedProfile::named($name);

            $this->assertSame($name, $profile->name());
            $this->assertSame($values['services'], $profile->services(), "{$name} : services");
            $this->assertSame($values['beds'], $profile->beds(), "{$name} : lits");
            $this->assertSame($values['patients'], $profile->patients(), "{$name} : patients");
            $this->assertSame($values['sejours'], $profile->sejours(), "{$name} : séjours");
            $this->assertSame($values['window'], $profile->windowMonths(), "{$name} : fenêtre");
        }
    }

    public function testDefaultProfileIsMedium(): void
    {
        $this->assertSame('medium', SeedProfile::named()->name());
        $this->assertSame(SeedProfile::MEDIUM, SeedProfile::DEFAULT_PROFILE);
    }

    /**
     * Les chambres doivent porter exactement les lits demandés : ni un lit de moins (l'opérateur
     * a demandé une capacité), ni un lit de plus (un lit fantôme fausserait l'occupation).
     */
    public function testRoomsCarryExactlyTheRequestedBeds(): void
    {
        foreach (SeedProfile::names() as $name) {
            $profile = SeedProfile::named($name);

            $doubles = $profile->doubleRooms();
            $singles = $profile->rooms() - $doubles;

            $this->assertSame(
                $profile->beds(),
                (2 * $doubles) + $singles,
                "{$name} : les chambres ne portent pas exactement les lits du profil"
            );
            $this->assertGreaterThan(0, $doubles, "{$name} : sans chambre double, plus de sujet de placement");
            $this->assertGreaterThan(0, $singles, "{$name} : sans chambre individuelle, chambre_seule est inutile");
        }
    }

    public function testRoomArithmeticOnAKnownScale(): void
    {
        $profile = SeedProfile::named('small');

        $this->assertSame(20, $profile->doubleRooms());
        $this->assertSame(40, $profile->rooms());
    }

    public function testWriterIsOrmOnlyForTheSmallestProfile(): void
    {
        $this->assertSame(SeedProfile::WRITER_ORM, SeedProfile::named('small')->defaultWriter());

        // Depuis que les séjours sont déduits de la capacité, `medium` pèse 82 000 objets : le
        // chemin ORM y passerait des heures.
        foreach (['medium', 'large', 'xl'] as $name) {
            $this->assertSame(SeedProfile::WRITER_BULK, SeedProfile::named($name)->defaultWriter(), $name);
        }
    }

    /**
     * Le chemin d'écriture suit le VOLUME, pas le nom du profil : un `small` gonflé doit
     * basculer, sinon il passerait des heures en ORM.
     */
    public function testAnInflatedSmallProfileSwitchesToBulk(): void
    {
        $profile = SeedProfile::named('small', ['patients' => 50000, 'sejours' => 300]);

        $this->assertSame(SeedProfile::WRITER_BULK, $profile->defaultWriter());
        $this->assertFalse($profile->tracesMassEntities());
    }

    public function testTraceabilityFollowsTheWritePath(): void
    {
        $this->assertTrue(SeedProfile::named('small')->tracesMassEntities());
        $this->assertFalse(SeedProfile::named('large')->tracesMassEntities());
    }

    public function testOverridesApply(): void
    {
        $profile = SeedProfile::named('small', ['services' => 7, 'sejours' => 999]);

        $this->assertSame(7, $profile->services());
        $this->assertSame(999, $profile->sejours());
        // Les dimensions non surchargées gardent la valeur du profil.
        $this->assertSame(60, $profile->beds());

        // Et les patients suivent les séjours surchargés, pas le profil d'origine : c'est la même
        // règle de cohérence, appliquée à une demande explicite.
        $this->assertSame(400, $profile->patients());
    }

    /**
     * Une surcharge de séjours est TENUE, même si elle sort de la bande d'occupation : c'est une
     * demande explicite de l'opérateur, et il doit pouvoir produire un petit jeu de données.
     *
     * @throws SeedException
     */
    public function testAnExplicitSejourOverrideWins(): void
    {
        $profile = SeedProfile::named('medium', ['sejours' => 500]);

        $this->assertSame(500, $profile->sejours());
        $this->assertNotSame($profile->requiredSejours(), $profile->sejours());
    }

    /**
     * LA LOI DE LITTLE : l'occupation d'un lit est le produit d'un flux d'entrées par la durée
     * moyenne de séjour. Ce test vérifie que le nombre de séjours déduit reproduit bien l'occupation
     * visée — c'est la propriété qui rendait les anciens profils impossibles, d'un facteur 6 à 14.
     *
     * @throws SeedException
     */
    public function testTheDeducedStayCountSustainsTheTargetOccupancy(): void
    {
        foreach (SeedProfile::names() as $name) {
            $profile = SeedProfile::named($name);

            // Reconstitution du calcul à l'envers : combien de lits les séjours déduits
            // occupent-ils en moyenne ?
            $ward_beds = (int)round($profile->beds() * 8 / 10);
            $comp      = $profile->sejours() * ($profile->sejourTypeMix()['comp'] ?? 0) / 100;
            $days      = $profile->windowMonths() * 30.44;

            $duration  = $profile->sejourDuration('comp');
            $mean_stay = ($duration[0] + $duration[1]) / 2;

            // Les soins continus sont exclus de la comparaison : ils ont leur propre bande et leur
            // propre durée. On ne vérifie ici que l'ordre de grandeur global.
            $bed_days = $comp * $mean_stay;
            $capacity = $profile->beds() * $days;

            $rate = 100 * $bed_days / $capacity;

            $this->assertGreaterThan(
                60,
                $rate,
                "Profil {$name} : les séjours déduits n'occuperaient les lits qu'à "
                . round($rate) . ' %, il en faut davantage'
            );
            $this->assertLessThan(
                95,
                $rate,
                "Profil {$name} : les séjours déduits demanderaient " . round($rate)
                . ' % d\'occupation, ce qui n\'est pas soutenable'
            );

            $this->assertGreaterThan(0, $ward_beds);
        }
    }

    public function testUnknownProfileIsRejected(): void
    {
        $this->expectException(SeedException::class);
        $this->expectExceptionMessageMatches('/Profil inconnu/');
        SeedProfile::named('gigantesque');
    }

    public function testUnknownOverrideIsRejected(): void
    {
        $this->expectException(SeedException::class);
        $this->expectExceptionMessageMatches('/Surcharge inconnue/');
        SeedProfile::named('small', ['lits' => 10]);
    }

    public function testNonPositiveOverrideIsRejected(): void
    {
        $this->expectException(SeedException::class);
        SeedProfile::named('small', ['beds' => 0]);
    }

    public function testMixesAreExpressedInWholePercent(): void
    {
        $profile = SeedProfile::named();

        $this->assertSame(100, array_sum($profile->sejourTypeMix()), 'Le mix de types doit faire 100 %');
        $this->assertSame(100, array_sum($profile->sejourStateMix()), 'La répartition des états doit faire 100 %');

        foreach (array_merge($profile->sejourTypeMix(), $profile->sejourStateMix()) as $key => $value) {
            $this->assertIsInt($value, "Le poids de '{$key}' doit être entier");
        }
    }

    public function testSejourTypesAreKnownToTheModel(): void
    {
        // Liste de CSejour::$_props['type'] : un type inconnu ferait échouer tous les store().
        $known = ['comp', 'ambu', 'exte', 'seances', 'ssr', 'psy', 'urg', 'consult'];

        foreach (array_keys(SeedProfile::named()->sejourTypeMix()) as $type) {
            $this->assertContains($type, $known, "Type de séjour inconnu du modèle : '{$type}'");
        }
    }

    public function testOccupancyBandIsTheAnnouncedOne(): void
    {
        $this->assertSame([75, 85], SeedProfile::named()->occupancyBand());
    }

    public function testDistributeSpreadsTheRemainderOnTheFirstBuckets(): void
    {
        $this->assertSame([29, 29, 29, 29, 28, 28, 28], SeedProfile::distribute(200, 7));
        $this->assertSame([1, 1, 1], SeedProfile::distribute(3, 3));
        $this->assertSame([1, 1, 0], SeedProfile::distribute(2, 3));
        $this->assertSame([0, 0], SeedProfile::distribute(0, 2));
    }

    public function testDistributeAlwaysConservesTheTotal(): void
    {
        foreach ([[200, 7], [60, 4], [1200, 40], [6000, 20], [1, 9]] as [$total, $buckets]) {
            $shares = SeedProfile::distribute($total, $buckets);

            $this->assertCount($buckets, $shares);
            $this->assertSame($total, array_sum($shares), "distribute({$total}, {$buckets}) perd des unités");
        }
    }

    public function testDistributeRejectsZeroBuckets(): void
    {
        $this->expectException(SeedException::class);
        SeedProfile::distribute(10, 0);
    }

    /**
     * Le bloc est dimensionné sur l'ACTIVITÉ, pas sur les lits.
     *
     * Le premier jet ouvrait une salle par 90 lits : le profil medium recevait trois salles pour
     * 478 interventions sur quinze mois, et son planning opératoire était vide à 83 %. Les deux
     * grandeurs n'ont aucune raison d'être proportionnelles.
     */
    public function testTheatreIsSizedOnActivityAndNotOnBeds(): void
    {
        // MÊMES LITS, activité différente : c'est la démonstration du découplage. Le premier jet
        // déduisait les salles des lits, et les deux cas ci-dessous auraient donné le même bloc.
        $busy  = SeedProfile::named('medium');
        $quiet = SeedProfile::named('medium', ['sejours' => 2000]);

        $this->assertSame($busy->beds(), $quiet->beds());

        // 10 services, dont 3 chirurgicaux et 2 de soins continus.
        $busy_theatre  = $busy->operatingTheatre(3, 8);
        $quiet_theatre = $quiet->operatingTheatre(3, 8);

        $this->assertSame(1, $quiet_theatre['rooms'], '2 000 séjours ne remplissent qu\'une salle');
        $this->assertGreaterThan(
            $quiet_theatre['rooms'] * $quiet_theatre['sessions'],
            $busy_theatre['rooms'] * $busy_theatre['sessions'],
            "C'est le nombre d'actes qui remplit un bloc, et donc lui qui le dimensionne"
        );
    }

    /**
     * Le remplissage du bloc doit rester lisible sur tous les profils livrés : ni un planning désert,
     * ni un planning saturé où les interventions ne trouveraient plus de créneau.
     *
     * La mesure porte sur la FENÊTRE PASSÉE, où tombent 90 % des interventions parce que 90 % des
     * séjours sont clos. La mesurer sur toute l'étendue des plages — horizon programmé compris —
     * diluait la demande sur une période où elle n'est pas : le bloc paraissait rempli à 73 % alors
     * qu'il saturait à plus de 80 % sur l'historique.
     *
     * @throws SeedException
     */
    public function testEveryDeliveredProfileFillsItsTheatrePlausibly(): void
    {
        foreach (SeedProfile::names() as $name) {
            $profile = SeedProfile::named($name);

            // Composition des profils livrés, cf. Specialities : ces comptes sont ceux du
            // catalogue, et le test échouera si le catalogue change sans qu'on le regarde.
            $surgical = ['small' => 1, 'medium' => 3, 'large' => 7, 'xl' => 14][$name];
            $ward     = ['small' => 3, 'medium' => 8, 'large' => 18, 'xl' => 37][$name];

            $theatre    = $profile->operatingTheatre($surgical, $ward);
            $operations = $profile->expectedOperations($surgical, $ward);

            // Les lits d'accueil des urgences ne portent pas d'activité programmée.
            $this->assertGreaterThan(0, $profile->emergencyBeds(), "{$name} : lits d'urgence");

            $weekdays = (int)round($profile->windowMonths() * 30.44 * 5 / 7);
            $capacity = $theatre['rooms'] * $theatre['sessions'] * $weekdays;

            // 90 % des séjours sont clos, donc 90 % des interventions sont dans l'historique.
            $usage = 100 * (0.9 * $operations / 2) / $capacity;

            $this->assertGreaterThanOrEqual(
                50,
                $usage,
                "Profil {$name} : plages occupées à " . round($usage) . ' %, le planning paraît désert'
            );
            $this->assertLessThanOrEqual(
                75,
                $usage,
                "Profil {$name} : plages occupées à " . round($usage) . ' %, au-delà les interventions'
                . ' en trop ne trouvent plus de créneau'
            );

            // Et un bloc ouvre ses deux vacations avant d'ouvrir une salle de plus.
            $this->assertSame(
                2,
                $theatre['sessions'],
                "Profil {$name} : quinze salles ouvertes le matin seulement ne ressemblent à rien"
            );
        }
    }

    public function testAProfileWithoutSurgeryExpectsNoOperation(): void
    {
        $this->assertSame(0, SeedProfile::named('small')->expectedOperations(0, 4));
    }

    /**
     * L'horizon futur sert à DEUX choses, et c'est pour cela qu'il est porté par le profil : les
     * admissions programmées, et les plages que le bloc doit ouvrir pour les accueillir.
     */
    public function testForwardHorizonIsAQuarterOfTheWindowAndNeverZero(): void
    {
        $this->assertSame(1, SeedProfile::named('small')->futureHorizonMonths(), '3 mois / 4, plancher à 1');
        $this->assertSame(3, SeedProfile::named('medium')->futureHorizonMonths());
        $this->assertSame(6, SeedProfile::named('xl')->futureHorizonMonths());
        $this->assertSame(1, SeedProfile::named('small', ['window' => 1])->futureHorizonMonths());
    }

    public function testIntensiveCareHasItsOwnLowerBand(): void
    {
        $profile = SeedProfile::named('small');

        [$ward_min, $ward_max] = $profile->occupancyBand();
        [$icu_min, $icu_max]   = $profile->icuOccupancyBand();

        $this->assertLessThan($ward_min, $icu_min, 'Une réanimation garde de la marge');
        $this->assertLessThan($ward_max, $icu_max);
        $this->assertGreaterThan(0, $icu_min, 'Mais elle n\'est pas vide pour autant');
    }

    public function testDescribeReportsWhatMakesTheRunReproducible(): void
    {
        $description = SeedProfile::named('small', ['patients' => 42])->describe();

        $this->assertSame('small', $description['profil']);
        $this->assertSame(42, $description['patients']);
        $this->assertArrayHasKey('ecriture', $description);
        $this->assertArrayHasKey('objets', $description);
    }
}
