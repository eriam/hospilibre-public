<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Tests\Unit\Seed;

use Ox\Cli\Seed\SeedException;
use Ox\Cli\Seed\Decor\SpecialityCatalogue;
use Ox\Cli\Seed\Specialities;
use PHPUnit\Framework\TestCase;

/**
 * Le catalogue de spécialités, et ce que le reste du seed en déduit.
 *
 * Deux choses s'y jouent, et les deux ont produit des incohérences visibles dans l'application :
 * les ACTES, qui doivent appartenir à la spécialité qui les réalise, et la composition du
 * catalogue, sur laquelle sont dimensionnés le bloc opératoire et l'effectif d'anesthésie.
 *
 * Aucune base de données n'est nécessaire.
 */
class SpecialitiesTest extends TestCase
{
    /**
     * Le catalogue français, assemblé par sa fabrique.
     *
     * Les recherches ne sont plus des méthodes statiques de `Specialities` : elles vivent dans
     * `ArrayCatalogue`, partagée par tous les décors, et `Specialities` n'en porte plus que les données.
     *
     * @throws SeedException
     */
    private function catalogue(): SpecialityCatalogue
    {
        return Specialities::catalogue();
    }

    /**
     * Toute spécialité chirurgicale du catalogue doit avoir ses actes.
     *
     * Sans ce test, ajouter une spécialité chirurgicale au catalogue sans lui donner d'actes
     * ferait échouer le seed en cours de route, et seulement sur les profils assez grands pour
     * l'atteindre.
     *
     * @throws SeedException
     */
    public function testEverySurgicalSpecialityHasItsOwnProcedures(): void
    {
        foreach ($this->catalogue()->first($this->catalogue()->count()) as $spec) {
            if (!$spec['surgical']) {
                continue;
            }

            $procedures = $this->catalogue()->procedures($spec['slug']);

            $this->assertGreaterThanOrEqual(
                4,
                count($procedures),
                "La spécialité '{$spec['slug']}' doit avoir de quoi varier son programme"
            );
            $this->assertSame(
                $procedures,
                array_unique($procedures),
                "Actes en doublon pour '{$spec['slug']}'"
            );
        }
    }

    /**
     * Le défaut constaté : les actes étaient tirés dans une liste unique, entièrement viscérale,
     * et la chirurgie orthopédique réalisait des colectomies.
     *
     * @throws SeedException
     */
    public function testOrthopaedicsDoesNotPerformDigestiveSurgery(): void
    {
        $orthopaedics = $this->catalogue()->procedures('chirurgie-orthopedique');

        $this->assertNotContains('Colectomie droite', $orthopaedics);
        $this->assertNotContains('Thyroïdectomie totale', $orthopaedics);
        $this->assertContains('Prothèse totale de hanche', $orthopaedics);
    }

    /**
     * Demander les actes d'une spécialité qui n'en a pas doit ÉCHOUER, et non rendre une liste
     * vide : un programme opératoire sans libellé serait illisible, et l'erreur silencieuse.
     */
    public function testAMissingProcedureListIsAnError(): void
    {
        $this->expectException(SeedException::class);
        $this->expectExceptionMessageMatches('/Aucun acte défini/');

        $this->catalogue()->procedures('cardiologie');
    }

    public function testOnlyPaediatricSpecialitiesHostChildren(): void
    {
        $this->assertTrue($this->catalogue()->isPaediatric('pediatrie'));
        $this->assertTrue($this->catalogue()->isPaediatric('chirurgie-pediatrique'));
        $this->assertFalse($this->catalogue()->isPaediatric('geriatrie'));
        $this->assertFalse($this->catalogue()->isPaediatric('medecine-interne'));
    }

    /**
     * Ce qu'un service ADMET, et pas seulement ce qu'il refuse.
     *
     * Le premier jet n'avait que la moitié de la règle : il interdisait les mineurs là où aucun
     * service ne peut les prendre, mais ne disait pas ce que chaque service admet. Mesuré sur un
     * profil `medium` : 111 hommes sur 240 séjours d'obstétrique, et dix césariennes sur des
     * patients masculins dont un de 93 ans.
     */
    public function testEachSpecialityDeclaresWhoItAdmits(): void
    {
        // Le cas général : un adulte de tout âge, des deux sexes.
        $this->assertSame(
            ['min_age' => 18, 'max_age' => 95, 'sex' => null],
            $this->catalogue()->patientProfile('medecine-interne')
        );

        // Et les exceptions, qui sont le sujet.
        $this->assertSame(
            ['min_age' => 1, 'max_age' => 17, 'sex' => null],
            $this->catalogue()->patientProfile('pediatrie'),
            'Une pédiatrie ne reçoit que des mineurs'
        );
        $this->assertSame(
            ['min_age' => 18, 'max_age' => 45, 'sex' => 'f'],
            $this->catalogue()->patientProfile('gynecologie-obstetrique'),
            "Une obstétrique ne reçoit ni hommes, ni femmes hors d'âge de l'être"
        );
        $this->assertSame(
            70,
            $this->catalogue()->patientProfile('geriatrie')['min_age'],
            'Une gériatrie qui reçoit des patients de dix-huit ans ne trompe personne'
        );
    }

    /**
     * Les bornes d'un profil doivent rester cohérentes entre elles, et dans la population que le
     * seed sait produire : un profil qui demanderait des centenaires ne trouverait jamais personne.
     *
     * @throws SeedException
     */
    public function testEveryProfileIsSatisfiableByThePopulation(): void
    {
        foreach ($this->catalogue()->first($this->catalogue()->count()) as $spec) {
            $profile = $this->catalogue()->patientProfile($spec['slug']);
            $slug    = $spec['slug'];

            $this->assertLessThan(
                $profile['max_age'],
                $profile['min_age'],
                "Profil vide pour '{$slug}'"
            );
            $this->assertLessThanOrEqual(
                Specialities::ADULT_MAX_AGE,
                $profile['max_age'],
                "Le profil de '{$slug}' demande des patients plus âgés que la population n'en produit"
            );
            $this->assertGreaterThanOrEqual(1, $profile['min_age'], $slug);

            // Un service non pédiatrique n'admet pas de mineurs, et réciproquement : c'est ce qui
            // fait qu'un établissement sans pédiatrie peut se passer de population mineure.
            $admits_minors = $profile['min_age'] < Specialities::ADULT_AGE;
            $this->assertSame(
                $this->catalogue()->isPaediatric($slug),
                $admits_minors,
                "'{$slug}' : la tranche d'âge et le caractère pédiatrique doivent se répondre"
            );
        }
    }

    /**
     * Un acte réservé à un sexe ne doit pas être proposé à l'autre. L'urologie et la chirurgie
     * pédiatrique opèrent les deux sexes, mais pas des mêmes choses.
     *
     * @throws SeedException
     */
    public function testSexSpecificProceduresAreFilteredOut(): void
    {
        $female = $this->catalogue()->procedures('urologie', 'f');
        $male   = $this->catalogue()->procedures('urologie', 'm');

        $this->assertNotContains('Résection endoscopique de prostate', $female);
        $this->assertNotContains('Cure de varicocèle', $female);
        $this->assertNotContains('Circoncision', $female);
        $this->assertContains('Résection endoscopique de prostate', $male);

        // Les actes neutres restent proposés aux deux.
        $this->assertContains('Urétéroscopie pour calcul', $female);
        $this->assertContains('Urétéroscopie pour calcul', $male);

        $this->assertNotContains('Orchidopexie', $this->catalogue()->procedures('chirurgie-pediatrique', 'f'));
        $this->assertNotContains('Reconstruction mammaire', $this->catalogue()->procedures('chirurgie-plastique', 'm'));
    }

    /**
     * Sans sexe demandé, la liste est complète : c'est ce que consultent les tests et les outils
     * qui vérifient la cohérence d'un libellé avec sa spécialité.
     *
     * @throws SeedException
     */
    public function testWithoutASexTheWholeListIsReturned(): void
    {
        $all = $this->catalogue()->procedures('urologie');

        $this->assertContains('Résection endoscopique de prostate', $all);
        $this->assertContains('Urétéroscopie pour calcul', $all);
    }

    /**
     * La composition du catalogue est ce sur quoi sont dimensionnés le bloc et l'anesthésie.
     * Elle est donc figée ici : la changer sans le vouloir déplacerait ces deux dimensionnements.
     *
     * @throws SeedException
     */
    public function testCountsDescribeTheDeliveredProfiles(): void
    {
        $expected = [
            6  => ['surgical' => 1, 'ward' => 3, 'intensive' => 1, 'emergency' => 2, 'paediatric' => 0],
            12 => ['surgical' => 3, 'ward' => 8, 'intensive' => 2, 'emergency' => 2, 'paediatric' => 0],
            22 => ['surgical' => 7, 'ward' => 18, 'intensive' => 2, 'emergency' => 2, 'paediatric' => 0],
            42 => ['surgical' => 14, 'ward' => 37, 'intensive' => 3, 'emergency' => 2, 'paediatric' => 2],
        ];

        foreach ($expected as $services => $counts) {
            $this->assertSame($counts, $this->catalogue()->counts($services), "Catalogue à {$services} services");
            $this->assertSame(
                $services,
                $counts['ward'] + $counts['intensive'] + $counts['emergency'],
                "Une unité est soit d'hospitalisation, soit de soins continus, soit d'accueil des"
                . " urgences : il n'y a pas de quatrième cas"
            );
        }
    }

    /**
     * Les profils livrés n'ouvrent pas de pédiatrie : c'est la prémisse de la règle d'âge des
     * patients, et elle doit être vérifiée plutôt que supposée.
     *
     * @throws SeedException
     */
    public function testTheFrontDoorComesFirstInTheCatalogue(): void
    {
        // L'ordre du catalogue est significatif : le plus petit profil doit avoir de quoi héberger
        // un séjour urgent, sinon 10 % de ses séjours restent des coquilles vides.
        $first = $this->catalogue()->first(2);

        $this->assertSame('urgences', $first[0]['slug']);
        $this->assertSame('uhcd', $first[1]['slug']);

        $this->assertTrue($this->catalogue()->isEmergency('urgences'));
        $this->assertTrue($this->catalogue()->isEmergency('uhcd'));
        $this->assertFalse($this->catalogue()->isEmergency('medecine-interne'));

        // Et les drapeaux que le socle exige pour autoriser l'hébergement.
        $this->assertSame(['urgence'], $this->catalogue()->serviceFlags('urgences'));
        $this->assertSame(['uhcd'], $this->catalogue()->serviceFlags('uhcd'));
        $this->assertSame([], $this->catalogue()->serviceFlags('cardiologie'));
    }

    public function testDeliveredProfilesBelowXlHostNoChildren(): void
    {
        foreach ([6, 12, 22] as $services) {
            $this->assertSame(
                0,
                $this->catalogue()->counts($services)['paediatric'],
                "Un établissement de {$services} services n'accueille pas d'enfants"
            );
        }

        $this->assertGreaterThan(0, $this->catalogue()->counts(42)['paediatric']);
    }
}
