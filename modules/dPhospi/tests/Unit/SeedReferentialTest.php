<?php

/**
 * @package Hospilibre\Hospi
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Hospilibre\Hospi\Tests\Unit;

use Exception;
use Hospilibre\Bloc\CPlageOp;
use Hospilibre\Hospi\CChambre;
use Ox\Cli\Seed\ModuleGuard;
use Ox\Cli\Seed\OrmWriter;
use Ox\Cli\Seed\Referential;
use Ox\Cli\Seed\ReferentialBuilder;
use Ox\Cli\Seed\SeedContext;
use Ox\Cli\Seed\SeedProfile;
use Ox\Cli\Seed\SeedPurger;
use Ox\Cli\Seed\SeedRandom;
use Ox\Tests\UnitTestHospilibre;
use Throwable;

/**
 * Invariants du référentiel du seed, vérifiés sur une base réelle.
 *
 * Ces invariants ne peuvent PAS être vérifiés sans base : ils portent sur des liens
 * (`lit -> chambre -> service`, `plage -> salle`) qui n'existent qu'une fois les identifiants
 * attribués. C'est la raison pour laquelle ce test est ici et non dans cli/tests/Unit, où vit la
 * partie du moteur qui se teste à vide.
 *
 * Tout ce que le test écrit est tracé dans CFixturesReference sous sa propre classe, et purgé
 * en fin de test : une instance de démonstration ne doit pas se retrouver avec un établissement
 * fantôme créé par la suite de tests.
 */
class SeedReferentialTest extends UnitTestHospilibre
{
    /** Instant de référence FIXE : sans lui, les plages du bloc changeraient chaque jour. */
    private const NOW = '2026-06-15 09:00:00';

    private const SEED = 4242;

    /** @var Referential|null */
    private $referential;

    protected function tearDown(): void
    {
        $this->purgeSeed();

        parent::tearDown();
    }

    /**
     * @throws Exception
     */
    public function testTheGuardRefusesAnInactiveModule(): void
    {
        // Le noyau est actif sur une instance installée : la garde doit passer.
        ModuleGuard::assertActive();

        $this->assertSame([], ModuleGuard::inactiveModules());

        // Et elle doit refuser un module qui n'existe pas, plutôt que de laisser le seed
        // produire un graphe troué en silence.
        $this->expectExceptionMessageMatches('/Module\(s\) inactif\(s\)/');
        ModuleGuard::assertActive(['moduleQuiNExistePas']);
    }

    /**
     * @throws Exception
     */
    public function testSmallProfileBuildsTheAnnouncedTopology(): void
    {
        $profile = SeedProfile::named('small');
        $ref     = $this->build();

        $this->assertCount($profile->services(), $ref->services(), 'Nombre de services du profil');
        $this->assertSame($profile->beds(), $ref->bedCount(), 'Nombre de lits du profil');

        $rooms = 0;
        foreach ($ref->services() as $entry) {
            $service_rooms = [];
            foreach ($entry['beds'] as $bed) {
                $service_rooms[$bed->chambre_id] = true;
            }
            $rooms += count($service_rooms);
        }
        $this->assertSame($profile->rooms(), $rooms, 'Nombre de chambres du profil');
    }

    /**
     * Invariant 1 : tout lit appartient à une chambre du service auquel le référentiel le
     * rattache. C'est ce que le motif des anciens générateurs ne pouvait pas garantir, puisqu'ils
     * tiraient service et lit indépendamment.
     *
     * @throws Exception
     */
    public function testEveryBedBelongsToItsOwnService(): void
    {
        $ref = $this->build();

        foreach ($ref->services() as $slug => $entry) {
            $service_id = $entry['service']->_id;
            $this->assertNotEmpty($service_id, "Service '{$slug}' sans identifiant");

            foreach ($entry['beds'] as $bed) {
                $room = new CChambre();
                $room->load($bed->chambre_id);

                $this->assertNotNull($room->_id, "Lit {$bed->_id} sans chambre");
                $this->assertEquals(
                    $service_id,
                    $room->service_id,
                    "Le lit '{$bed->nom}' est rattaché au service '{$slug}' mais sa chambre appartient"
                    . " au service {$room->service_id}"
                );
            }
        }
    }

    /**
     * @throws Exception
     */
    public function testBedsAndRoomsCarryUsableIdentifiers(): void
    {
        $ref = $this->build();

        $codes = [];
        foreach ($ref->services() as $entry) {
            foreach ($entry['beds'] as $bed) {
                $this->assertNotEmpty($bed->code, 'CLit.code est notNull');
                $this->assertNotEmpty($bed->nom, 'CLit.nom est notNull');
                $this->assertArrayNotHasKey($bed->code, $codes, "Code de lit en doublon : {$bed->code}");
                $codes[$bed->code] = true;
            }
        }
    }

    /**
     * Invariant 4, partie référentiel : chaque plage appartient à une salle, ses bornes sont
     * ordonnées, et elle tient dans ses horaires de référence. Le rattachement des interventions
     * viendra avec les séjours.
     *
     * @throws Exception
     */
    public function testOperatingSlotsAreCoherent(): void
    {
        $ref = $this->build();

        $this->assertNotEmpty($ref->rooms(), 'Au moins une salle de bloc');
        $this->assertNotEmpty($ref->slots(), 'Au moins une plage opératoire');

        $room_ids = [];
        foreach ($ref->rooms() as $room) {
            $room_ids[$room->_id] = true;
        }

        $surgeon_ids = [];
        foreach ($ref->surgeons() as $surgeon) {
            $surgeon_ids[$surgeon->_id] = true;
        }

        foreach ($ref->slots() as $slot) {
            $this->assertArrayHasKey($slot->salle_id, $room_ids, 'Plage rattachée à une salle du bloc');
            $this->assertLessThan($slot->fin, $slot->debut, 'Plage dont la fin précède le début');
            $this->assertLessThanOrEqual(
                $slot->fin_reference,
                $slot->fin,
                'Plage dépassant son horaire de référence'
            );
            $this->assertGreaterThanOrEqual(
                $slot->debut_reference,
                $slot->debut,
                'Plage commençant avant son horaire de référence'
            );
            $this->assertArrayHasKey(
                $slot->chir_id,
                $surgeon_ids,
                'Plage tenue par un praticien de fonction chirurgicale'
            );
        }
    }

    /**
     * Les vacations se partagent équitablement entre les équipes chirurgicales.
     *
     * Une plage tirée au hasard parmi tous les chirurgiens donnait un programme où la spécialité qui
     * opère n'a rien à voir avec l'intervention. Le tour de rôle garantit à la fois la cohérence et
     * l'équité — une spécialité qui recevrait deux fois moins de vacations qu'une autre ne pourrait
     * pas opérer ses patients.
     *
     * @throws Exception
     */
    public function testVacationsAreSharedEvenlyBetweenSurgicalTeams(): void
    {
        $ref           = $this->build();
        $specialities  = $ref->surgicalSpecialities();
        $per_speciality = [];

        $this->assertNotEmpty($specialities, 'Le profil doit comporter une spécialité chirurgicale');

        foreach ($ref->slots() as $slot) {
            // Les gardes du bloc d'urgence suivent leur propre rotation et ne se partagent pas comme
            // le programme : une garde par jour, quelle que soit la taille du bloc programmé.
            if ($ref->isEmergencySlot((int)$slot->_id)) {
                continue;
            }

            $slug = $ref->specialityOf((int)$slot->chir_id);

            $this->assertNotNull($slug, "Plage du {$slot->date} tenue par un praticien inconnu");
            $this->assertContains(
                $slug,
                $specialities,
                "Plage tenue par '{$slug}', qui n'est pas une spécialité chirurgicale"
            );

            $per_speciality[$slug] = ($per_speciality[$slug] ?? 0) + 1;
        }

        $this->assertCount(
            count($specialities),
            $per_speciality,
            'Toute spécialité chirurgicale doit avoir des vacations'
        );

        // Tour de rôle strict : l'écart entre la plus et la moins servie ne peut pas dépasser une
        // vacation.
        $this->assertLessThanOrEqual(
            1,
            max($per_speciality) - min($per_speciality),
            'Les vacations doivent se partager à une près : ' . json_encode($per_speciality)
        );
    }

    /**
     * Chaque vacation doit porter un anesthésiste, et deux salles ouvertes en même temps ne peuvent
     * pas se partager le même.
     *
     * Sans cette affectation, l'effectif d'anesthésie n'apparaissait nulle part dans l'activité :
     * il était dimensionné sur le nombre de SERVICES chirurgicaux — deux anesthésistes pour trois
     * salles — et rien ne rendait cette invraisemblance visible.
     *
     * @throws Exception
     */
    public function testEveryVacationHasItsOwnAnaesthetist(): void
    {
        $ref = $this->build();

        $anaesthetists = [];
        foreach ($ref->anaesthetists() as $practitioner) {
            $anaesthetists[$practitioner->_id] = true;
        }

        $this->assertGreaterThanOrEqual(
            count($ref->rooms()),
            count($anaesthetists),
            'Une salle en activité occupe un anesthésiste du début à la fin'
        );

        $on_duty = [];

        foreach ($ref->slots() as $slot) {
            $this->assertNotEmpty($slot->anesth_id, "Plage du {$slot->date} sans anesthésiste");
            $this->assertArrayHasKey(
                $slot->anesth_id,
                $anaesthetists,
                'Plage tenue par un praticien qui n\'est pas de la fonction anesthésie'
            );

            $key = $slot->date . '|' . $slot->debut . '|' . $slot->anesth_id;
            $this->assertArrayNotHasKey(
                $key,
                $on_duty,
                "L'anesthésiste {$slot->anesth_id} tient deux salles le {$slot->date} à {$slot->debut}"
            );
            $on_duty[$key] = true;
        }
    }

    /**
     * Le bloc d'urgence ouvre TOUS LES JOURS et sur les vingt-quatre heures, et son équipe de garde
     * n'est pas celle du programme.
     *
     * Sans ce second bloc, les seules plages ouvertes allaient du lundi au vendredi entre 8 h et
     * 18 h 30 : une appendicectomie à deux heures du matin n'existait pas dans le jeu de données.
     *
     * @throws Exception
     */
    public function testTheEmergencyTheatreIsOpenRoundTheClock(): void
    {
        $ref = $this->build();

        $emergency = [];
        $programme = [];

        foreach ($ref->slots() as $slot) {
            if ($ref->isEmergencySlot((int)$slot->_id)) {
                $emergency[$slot->date][] = $slot;
            } else {
                $programme[$slot->date][] = $slot;
            }
        }

        $this->assertNotEmpty($emergency, "Le bloc d'urgence doit avoir des plages");

        // Tous les jours, sans exception : c'est ce qui distingue une garde d'un programme.
        $weekend = 0;
        foreach ($emergency as $date => $slots) {
            $covered = 0;
            foreach ($slots as $slot) {
                // Minutes calculées sur les HORAIRES et non sur des horodatages : le 29 mars 2026,
                // jour du passage à l'heure d'été, la soustraction de deux timestamps rend une heure
                // de moins et ferait échouer ce test sur une couverture pourtant complète.
                $covered += self::minutes((string)$slot->fin) - self::minutes((string)$slot->debut);

                $this->assertTrue(
                    $ref->isEmergencyRoom((int)$slot->salle_id),
                    'Une plage de garde doit être dans la salle d\'urgence'
                );
            }

            // Vingt-quatre heures à la minute près : les trois gardes se succèdent sans trou.
            $this->assertGreaterThanOrEqual(
                24 * 60 - 1,
                $covered,
                "Le {$date}, la salle d'urgence n'est couverte que {$covered} minutes"
            );

            if ((int)date('N', strtotime($date)) > 5) {
                $weekend++;
            }
        }

        $this->assertGreaterThan(0, $weekend, 'Une garde existe aussi le week-end');

        // Et l'équipe de garde ne tient pas en même temps une salle du programme.
        foreach ($emergency as $date => $slots) {
            $on_call = [];
            foreach ($slots as $slot) {
                $on_call[(int)$slot->chir_id]   = true;
                $on_call[(int)$slot->anesth_id] = true;
            }

            foreach ($programme[$date] ?? [] as $slot) {
                $this->assertArrayNotHasKey(
                    (int)$slot->chir_id,
                    $on_call,
                    "Le {$date}, le chirurgien de garde tient aussi une salle du programme"
                );
                $this->assertArrayNotHasKey(
                    (int)$slot->anesth_id,
                    $on_call,
                    "Le {$date}, l'anesthésiste de garde tient aussi une salle du programme"
                );
            }
        }
    }

    /** Minutes depuis minuit d'un horaire 'H:i:s'. */
    private static function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }

    /**
     * Personne ne tient deux salles à la même heure.
     *
     * Les praticiens d'une vacation étaient tirés indépendamment les uns des autres : le même
     * chirurgien se retrouvait dans deux salles au même moment, ce qui se voit sur un tableau de
     * bloc.
     *
     * @throws Exception
     */
    public function testNoPractitionerHoldsTwoRoomsAtOnce(): void
    {
        $ref  = $this->build();
        $seen = [];

        foreach ($ref->slots() as $slot) {
            foreach ([(int)$slot->chir_id, (int)$slot->anesth_id] as $practitioner) {
                $key = $slot->date . '|' . $slot->debut . '|' . $practitioner;

                $this->assertArrayNotHasKey(
                    $key,
                    $seen,
                    "Le praticien {$practitioner} tient deux salles le {$slot->date} à {$slot->debut}"
                );

                $seen[$key] = true;
            }
        }

        $this->assertNotEmpty($seen);
    }

    /**
     * Les unités d'accueil des urgences doivent exister, avec les drapeaux que le socle exige.
     *
     * @throws Exception
     */
    public function testEmergencyUnitsCarryTheFlagsTheSocleRequires(): void
    {
        $ref = $this->build();

        $this->assertSame(['urgences', 'uhcd'], $ref->emergencySlugs());

        foreach ($ref->emergencySlugs() as $slug) {
            $service = $ref->service($slug);

            // L'un des quatre drapeaux que CAffectation::check() exige pour héberger un séjour
            // urgent. Sans eux, 10 % des séjours restaient sans lit.
            $this->assertTrue(
                (bool)$service->urgence || (bool)$service->uhcd,
                "Le service '{$slug}' ne porte aucun drapeau d'accueil des urgences"
            );

            $this->assertNotEmpty($ref->beds($slug), "L'unité '{$slug}' doit avoir des boxes");
        }

        $this->assertTrue((bool)$ref->service('urgences')->urgence);
        $this->assertTrue((bool)$ref->service('uhcd')->uhcd);
    }

    /**
     * Un bloc qui opérerait le week-end se remarque immédiatement sur un planning.
     *
     * @throws Exception
     */
    public function testNoOperatingSlotOnWeekends(): void
    {
        $ref = $this->build();

        foreach ($ref->slots() as $slot) {
            // Le bloc D'URGENCE, lui, ouvre le week-end : c'est même sa raison d'être.
            if ($ref->isEmergencySlot((int)$slot->_id)) {
                continue;
            }

            $weekday = (int)date('N', strtotime($slot->date));
            $this->assertLessThanOrEqual(5, $weekday, "Plage un week-end : {$slot->date}");
        }
    }

    /**
     * @throws Exception
     */
    public function testEverySlotIsReadableFromTheDatabase(): void
    {
        $ref = $this->build();

        $first = $ref->slots()[0];

        $reloaded = new CPlageOp();
        $reloaded->load($first->_id);

        $this->assertNotNull($reloaded->_id, 'La plage doit être réellement écrite en base');
        $this->assertEquals($first->salle_id, $reloaded->salle_id);
    }

    /**
     * Reproductibilité : même profil, même graine, même instant ⇒ même référentiel.
     *
     * La comparaison porte sur les TAGS et les libellés, pas sur les identifiants : ceux-ci
     * dépendent des auto-incréments de la base, que le seed ne prétend pas reproduire.
     *
     * @throws Exception
     */
    public function testSameSeedProducesTheSameReferential(): void
    {
        $first = $this->describe($this->build());

        // Purge indispensable avant de reconstruire : les noms d'utilisateur des praticiens sont
        // uniques en base, un second établissement à l'identique les heurterait.
        $this->purgeSeed();

        $second = $this->describe($this->build());

        $this->assertSame($first, $second);
    }

    /**
     * @throws Exception
     */
    public function testAnotherSeedProducesAnotherEstablishment(): void
    {
        $first = $this->describe($this->build(self::SEED));
        $this->purgeSeed();

        $second = $this->describe($this->build(self::SEED + 1));

        $this->assertNotSame(
            $first['etab'],
            $second['etab'],
            "Deux graines différentes doivent donner deux établissements différents"
        );
    }

    /**
     * @throws Exception
     */
    private function build(int $seed = self::SEED): Referential
    {
        $context = new SeedContext(
            SeedProfile::named('small'),
            new SeedRandom($seed),
            new OrmWriter(self::class),
            self::NOW
        );

        return $this->referential = (new ReferentialBuilder($context))->build();
    }

    /**
     * Description stable du référentiel, indépendante des identifiants.
     *
     * @return array<string, mixed>
     */
    private function describe(Referential $ref): array
    {
        $description = [
            'etab'     => $ref->group()->text,
            'services' => [],
            'salles'   => count($ref->rooms()),
            'plages'   => count($ref->slots()),
        ];

        foreach ($ref->services() as $slug => $entry) {
            $beds = [];
            foreach ($entry['beds'] as $bed) {
                $beds[] = $bed->nom;
            }

            $description['services'][$slug] = [
                'nom'  => $entry['service']->nom,
                'lits' => $beds,
            ];
        }

        return $description;
    }

    /**
     * Défait le jeu de données, par le MÊME chemin que « ox-seed:hospi --purge ».
     *
     * Passer par SeedPurger plutôt que par une purge recopiée dans le test a deux vertus : le
     * chemin de production est exercé à chaque exécution de la suite, et la vérification du
     * résultat ne peut pas être oubliée ici — c'est SeedPurger qui la porte.
     *
     * @throws Throwable
     */
    private function purgeSeed(): void
    {
        $purger = new SeedPurger(self::class);
        $purger->purge();
        $this->referential = null;

        $purger->assertClean();
    }
}
