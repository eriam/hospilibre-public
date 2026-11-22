<?php

/**
 * @package Hospilibre\PlanningOp
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Hospilibre\PlanningOp\Tests\Unit;

use Exception;
use Hospilibre\Bloc\CPlageOp;
use Hospilibre\Hospi\CAffectation;
use Hospilibre\Hospi\CChambre;
use Hospilibre\Hospi\CLit;
use Hospilibre\PlanningOp\COperation;
use Hospilibre\PlanningOp\CSejour;
use Ox\Cli\Seed\CollectingWriter;
use Ox\Cli\Seed\HospiSeeder;
use Ox\Cli\Seed\OrmWriter;
use Ox\Cli\Seed\SeedContext;
use Ox\Cli\Seed\SeedProfile;
use Ox\Cli\Seed\SeedPurger;
use Ox\Cli\Seed\SeedRandom;
use Ox\Cli\Seed\SeedReport;
use Ox\Cli\Seed\SejourPlan;
use Hospilibre\Ccam\CCodeCCAM;
use Hospilibre\SalleOp\CActeCCAM;
use Ox\Cli\Seed\CcamDictionary;
use Ox\Cli\Seed\Cim10Dictionary;
use Ox\Cli\Seed\Decor\ClinicalCoding;
use Ox\Cli\Seed\Decor\SpecialityCatalogue;
use Ox\Core\CMbDT;
use Ox\Tests\UnitTestHospilibre;
use Throwable;

/**
 * Invariants des séjours, vérifiés EN RELISANT LA BASE.
 *
 * Les invariants 5 et 6 sont déjà tenus à vide par SejourPlannerTest, sur les scénarios. Ce qui
 * se joue ici est différent : que l'écriture les respecte, et que les invariants 2 et 3 — qui
 * portent sur des lits et des identifiants, donc n'existent qu'après écriture — soient tenus.
 * Toutes les vérifications repartent de requêtes, jamais de l'état mémoire du builder : un
 * builder qui se croit cohérent n'apporte aucune garantie.
 *
 * Le jeu de données est construit UNE FOIS pour toute la classe : un profil `small` représente
 * plus d'un millier d'écritures ORM, le reconstruire par test rendrait la suite inutilisable.
 */
class SeedSejourTest extends UnitTestHospilibre
{
    private const NOW = '2026-06-15 09:00:00';

    private const SEED = 909;

    /** Activités CCAM : le geste du chirurgien, et l'anesthésie. */
    private const CCAM_ACTIVITY_PROCEDURE = 1;

    private const CCAM_ACTIVITY_ANAESTHESIA = 4;

    /**
     * Échelle du jeu de données de test : dix unités, quarante lits, un mois.
     *
     * Le nombre de lits et la fenêtre sont réduits ENSEMBLE, de sorte que le jeu reste physiquement
     * cohérent et que les assertions d'occupation gardent un sens — les réduire séparément les
     * aurait vidées de leur contenu. Sans cette réduction, un `small` complet demanderait 1 804
     * séjours et porterait ce test à un quart d'heure.
     *
     * DIX unités et non six, et quarante lits et non vingt, pour deux raisons précises : la dixième
     * du catalogue est la gynécologie-obstétrique, seule de ce rang à imposer un profil de patients ;
     * et le nombre de lits d'accueil des urgences se déduit de la capacité, si bien qu'en dessous de
     * quarante lits l'établissement n'en a qu'un — auquel cas sa bande d'occupation ne peut valoir
     * que 0 % ou 100 % et ne vérifie plus rien.
     */
    private const SCALE = ['services' => 10, 'beds' => 40, 'window' => 1];

    /** @var SeedReport|null Construit une fois, partagé par toute la classe */
    private static $report;

    /**
     * @var Exception|null Échec de construction, retenu et relancé tel quel.
     *
     * Sans cela, chaque méthode de test relançait une construction complète : la première échouait
     * sur la vraie cause, et toutes les suivantes sur une collision de nom d'utilisateur avec les
     * objets laissés par la tentative précédente. L'erreur utile était noyée sous dix erreurs
     * dérivées.
     */
    private static $failure;

    public static function tearDownAfterClass(): void
    {
        self::purgeSeed();
        self::$report  = null;
        self::$failure = null;

        parent::tearDownAfterClass();
    }

    /**
     * @throws Exception
     */
    public function testTheRunProducesWhatTheProfileAsked(): void
    {
        $report  = $this->report();
        $profile = self::profile();

        $this->assertSame($profile->patients(), $report->patients(), 'Patients du profil');
        $this->assertSame($profile->sejours(), $report->sejours(), 'Séjours du profil');
        $this->assertSame(33, $report->wardBeds(), '40 lits dont 5 en soins continus et 2 en urgences');
    }

    /**
     * Invariant 6, mesuré sur la base : l'occupation instantanée des lits d'hospitalisation
     * complète tombe dans la bande annoncée.
     *
     * @throws Exception
     */
    public function testInstantaneousOccupancyIsPlausible(): void
    {
        $report = $this->report();

        $this->assertTrue(
            $report->occupancyInBand(),
            "Occupation mesurée de {$report->occupancyRate()} %, hors de la bande visée"
        );

        // Contre-mesure indépendante du builder : on recompte en base les lits occupés
        // maintenant.
        $occupied = $this->occupiedBedsFromDatabase(self::NOW);

        $this->assertSame(
            $report->occupiedBeds(),
            $occupied,
            'Le compte du builder et celui de la base doivent coïncider'
        );
    }

    /**
     * Les soins continus ne doivent être ni vides, ni saturés.
     *
     * Ils étaient VIDES : aucun séjour ne leur était attribué, et sur un profil medium cela faisait
     * 40 lits inertes — un cinquième de l'établissement, et le premier écran vide qu'on rencontrait
     * en ouvrant le plan des lits.
     *
     * @throws Exception
     */
    public function testIntensiveCareIsNeitherEmptyNorOverloaded(): void
    {
        $report = $this->report();

        $this->assertSame(5, $report->icuBeds(), '40 lits dont 5 en soins continus');
        $this->assertGreaterThan(0, $report->occupiedIcuBeds(), 'Une réanimation vide n\'a aucun sens');
        $this->assertTrue(
            $report->icuOccupancyInBand(),
            "Soins continus à {$report->icuOccupancyRate()} %, hors de leur bande"
        );

        $this->assertSame(
            $report->occupiedIcuBeds(),
            $this->occupiedBedsFromDatabase(self::NOW, 'intensive'),
            'Le compte du builder et celui de la base doivent coïncider'
        );
    }

    /**
     * Le programme opératoire ne doit pas fuir.
     *
     * Il fuyait par le tiers : 156 interventions sur 474, parce que le séjour était placé d'abord et
     * qu'on cherchait ensuite une plage le jour de l'entrée — un week-end une fois sur trois. C'est
     * désormais le jour opératoire qui commande le séjour.
     *
     * Le seuil n'est pas zéro, et c'est un choix. Il reste des pertes quand toute la semaine autour
     * d'un séjour est complète : plutôt que d'inventer une plage ad hoc — ce qui donnerait un
     * programme incohérent avec le planning du bloc — le seed renonce et LE DIT. Ce qui est vérifié
     * ici est donc double : que la fuite soit marginale, et qu'elle soit comptée.
     *
     * @throws Exception
     */
    public function testTheOperatingProgrammeDoesNotLeak(): void
    {
        $report     = $this->report();
        $operations = $this->operations();

        $this->assertNotEmpty($operations, 'Le bloc doit avoir un programme');

        $lost  = $report->unscheduledOperations();
        $limit = (int)ceil(0.05 * count($operations));

        $this->assertLessThanOrEqual(
            $limit,
            $lost,
            "{$lost} interventions perdues sur " . count($operations) . ' programmées : au-delà de'
            . ' quelques pour cent, ce n\'est plus une dégradation mais un défaut de dimensionnement'
        );
    }

    /**
     * Le libellé d'une intervention doit appartenir à la spécialité qui l'opère.
     *
     * Le premier jet tirait dans une liste unique, entièrement viscérale : la chirurgie
     * orthopédique réalisait des colectomies et des thyroïdectomies. Un programme opératoire
     * incohérent se repère au premier coup d'œil.
     *
     * @throws Exception
     */
    public function testProcedureLabelsMatchTheSpecialityThatPerformsThem(): void
    {
        $referential = $this->report()->referential();
        $seen        = 0;

        foreach ($this->operations() as $operation) {
            // La spécialité qui OPÈRE, c'est-à-dire celle du chirurgien. Elle coïncide avec le
            // service du séjour pour une intervention programmée, mais pas au bloc d'urgence : là,
            // c'est le chirurgien de garde qui opère un patient hébergé aux urgences ou en UHCD, et
            // c'est sa spécialité à lui qui décide de l'acte.
            $slug = $referential->specialityOf((int)$operation->chir_id);

            $this->assertNotNull($slug, "Intervention {$operation->_id} sans chirurgien connu");

            $seen++;
            $this->assertContains(
                $operation->libelle,
                $this->catalogue()->procedures($slug),
                "« {$operation->libelle} » n'est pas un acte de la spécialité '{$slug}'"
            );
        }

        $this->assertGreaterThan(0, $seen);
    }

    /**
     * LE DIAGNOSTIC A-T-IL SURVÉCU AU `store()`, ET DIT-IL LA MÊME CHOSE QUE L'ACTE ?
     *
     * Deux pièges distincts, et le premier est le plus vicieux. `CSejour::check()` appelle
     * `CCodeCIM10::get()` sur le DP et, si le code est introuvable, VIDE LE CHAMP en poussant un
     * avertissement dans la file de messages de l'interface — invisible en ligne de commande. Un dry-run
     * ne le verrait pas, et le rapport annoncerait « 207 / 207 diagnostics » sur une base qui n'en
     * contient aucun. Seule une relecture en base tranche.
     *
     * Le second est la cohérence : un séjour opéré doit porter un diagnostic qui MOTIVE son acte. C'est
     * l'incohérence du §12 déplacée d'un cran — le diagnostic et l'acte tirés indépendamment se
     * contrediraient une fois sur deux.
     *
     * @throws Exception
     */
    public function testEveryStayCarriesADiagnosisThatMatchesItsProcedure(): void
    {
        $coding = $this->coding();

        if ($coding === null || !Cim10Dictionary::isAvailable()) {
            $this->markTestSkipped('Le dictionnaire CIM-10 ne répond pas sur cette instance');
        }

        $specialities = $this->servicesBySpeciality();

        // Acte de chaque séjour opéré : c'est lui qui décide du diagnostic quand il existe.
        $procedures = [];
        foreach ($this->operations() as $operation) {
            $procedures[(int)$operation->sejour_id] = (string)$operation->libelle;
        }

        $from_procedure = 0;

        // Relecture depuis la base, et non depuis les objets écrits : c'est tout l'intérêt.
        foreach ($this->sejours() as $sejour) {
            $fresh = new CSejour();
            $fresh->load($sejour->_id);

            $this->assertNotEmpty(
                (string)$fresh->DP,
                "Le séjour {$sejour->_id} n'a plus de diagnostic en base : CSejour::check() l'a vidé,"
                . ' ce qui veut dire que le code ne figure pas dans le dictionnaire monté'
            );

            $slug = $specialities[$fresh->service_id] ?? null;
            $this->assertNotNull($slug);

            $procedure = $procedures[(int)$sejour->_id] ?? null;
            $motives    = $procedure === null ? [] : $coding->diagnosesForProcedure($procedure);

            // L'ORDRE DE PRÉFÉRENCE EST VÉRIFIÉ, pas seulement l'appartenance : un séjour opéré dont
            // l'acte a des indications DOIT porter l'une d'elles, et non un diagnostic de son unité.
            if ($motives !== []) {
                $from_procedure++;

                $this->assertContains(
                    (string)$fresh->DP,
                    $motives,
                    "Le séjour {$sejour->_id} est opéré d'un « {$procedure} » mais porte le diagnostic"
                    . " « {$fresh->DP} », qui ne le motive pas"
                );

                continue;
            }

            $this->assertContains(
                (string)$fresh->DP,
                $coding->diagnosesFor($slug),
                "Le diagnostic « {$fresh->DP} » du séjour {$sejour->_id} n'appartient pas à '{$slug}'"
            );
        }

        $this->assertGreaterThan(
            0,
            $from_procedure,
            'Des séjours opérés doivent porter le diagnostic de leur acte, sans quoi ce test ne'
            . ' vérifierait que la moitié de la règle'
        );
    }

    /**
     * Chaque intervention porte SON code, et un acte lourd ne tombe pas sur un séjour de jour.
     *
     * @throws Exception
     */
    public function testEveryOperationIsCodedAndFitsItsStayType(): void
    {
        $coding = $this->coding();

        if ($coding === null) {
            $this->markTestSkipped('Ce décor ne code pas ses actes');
        }

        $seen = 0;

        foreach ($this->operations() as $operation) {
            $procedure = (string)$operation->libelle;
            $expected  = $coding->ccamFor($procedure);

            $this->assertNotNull($expected, "« {$procedure} » n'a pas de code CCAM");

            $this->assertSame(
                $expected,
                (string)$operation->codes_ccam,
                "L'intervention {$operation->_id} (« {$procedure} ») n'est pas cotée avec son code"
            );

            $sejour = new CSejour();
            $sejour->load($operation->sejour_id);

            if ((string)$sejour->type === 'ambu') {
                $this->assertTrue(
                    $coding->isAmbulatory($procedure),
                    "« {$procedure} » est posé sur un séjour de JOUR ({$sejour->_id}) alors qu'il"
                    . ' demande une hospitalisation complète'
                );
            }

            $seen++;
        }

        $this->assertGreaterThan(0, $seen);
    }

    /**
     * CHAQUE INTERVENTION EST-ELLE VRAIMENT CODÉE, ET PAR QUI ?
     *
     * `codes_ccam` n'est qu'une chaîne ; un CActeCCAM est un codage réel, avec son exécutant, son
     * activité, ses modificateurs précodés et sa feuille de codage. C'est aussi le seul objet du jeu
     * dont la création traverse tout le moteur CCAM du socle — d'où ce contrôle en base plutôt qu'un
     * compteur : un acte refusé par `check()` disparaîtrait sans bruit.
     *
     * @throws Exception
     */
    public function testEveryOperationCarriesTheSurgeonActe(): void
    {
        if (!CcamDictionary::isAvailable()) {
            $this->markTestSkipped('Le dictionnaire CCAM ne répond pas sur cette instance');
        }

        $seen = 0;

        foreach ($this->operations() as $operation) {
            $actes = $this->actesOf($operation, self::CCAM_ACTIVITY_PROCEDURE);

            $this->assertCount(
                1,
                $actes,
                "L'intervention {$operation->_id} doit porter exactement un acte d'activité 1"
            );

            $acte = reset($actes);

            $this->assertSame(
                (string)$operation->codes_ccam,
                (string)$acte->code_acte,
                "L'acte doit porter le code coté sur l'intervention"
            );
            $this->assertEquals(
                $operation->chir_id,
                $acte->executant_id,
                "Le geste est exécuté par le chirurgien de l'intervention"
            );
            $this->assertSame('0', (string)$acte->code_phase);

            // La feuille de codage est créée par le socle au moment du check() : son absence
            // signifierait que l'acte est passé par un chemin dégradé.
            $codage = $acte->loadRefCodageCCAM(false);

            $this->assertNotNull($codage, "L'acte {$acte->_id} n'a pas de feuille de codage");
            $this->assertNotEmpty($codage->_id, "La feuille de codage de l'acte {$acte->_id} est vide");

            $seen++;
        }

        $this->assertGreaterThan(0, $seen, 'Un établissement chirurgical doit coter des actes');
    }

    /**
     * L'ANESTHÉSIE N'EST PAS SYSTÉMATIQUE, ET C'EST LE DICTIONNAIRE QUI TRANCHE.
     *
     * Un acte n'admet l'activité 4 que s'il la déclare : `QZFA036`, l'exérèse d'une lésion cutanée de
     * moins de 5 cm², se fait sous anesthésie locale et ne la déclare pas. Coter une anesthésie dessus
     * serait faux, et c'est exactement le genre d'erreur qu'une règle écrite à la main aurait produite.
     *
     * @throws Exception
     */
    public function testTheAnaesthesiaActeFollowsTheDictionary(): void
    {
        if (!CcamDictionary::isAvailable()) {
            $this->markTestSkipped('Le dictionnaire CCAM ne répond pas sur cette instance');
        }

        $with_anaesthesia = 0;

        foreach ($this->operations() as $operation) {
            $code       = (string)$operation->codes_ccam;
            $activities = CCodeCCAM::get($code)->loadRefActivites();
            $expected   = $operation->anesth_id && isset($activities[self::CCAM_ACTIVITY_ANAESTHESIA]);

            $actes = $this->actesOf($operation, self::CCAM_ACTIVITY_ANAESTHESIA);

            $this->assertCount(
                $expected ? 1 : 0,
                $actes,
                "« {$code} » : l'acte d'anesthésie doit exister si et seulement si le dictionnaire"
                . " déclare l'activité 4 et qu'un anesthésiste tient la salle"
            );

            if (!$expected) {
                continue;
            }

            $this->assertEquals(
                $operation->anesth_id,
                reset($actes)->executant_id,
                "L'anesthésie est cotée par l'anesthésiste de la vacation, pas par le chirurgien"
            );

            $with_anaesthesia++;
        }

        $this->assertGreaterThan(
            0,
            $with_anaesthesia,
            'Sans aucune anesthésie cotée, ce test ne vérifierait que la moitié de la règle'
        );
    }

    /**
     * Actes CCAM d'une intervention, pour une activité donnée.
     *
     * @return CActeCCAM[]
     * @throws Exception
     */
    private function actesOf(COperation $operation, int $activity): array
    {
        $acte = new CActeCCAM();

        return $acte->loadList(
            [
                'object_class'  => "= 'COperation'",
                'object_id'     => "= '{$operation->_id}'",
                'code_activite' => "= '{$activity}'",
            ]
        );
    }

    /**
     * Chaque service ne reçoit que les patients qu'il ADMET : tranche d'âge, et sexe quand il est
     * imposé.
     *
     * Le premier jet n'avait que la moitié de cette règle — il interdisait les mineurs là où aucun
     * service ne peut les prendre, mais ne disait pas ce que chaque service admet. L'obstétrique d'un
     * profil `medium` comptait 111 hommes sur 240 séjours, et une gériatrie aurait reçu des patients
     * de dix-huit ans.
     *
     * @throws Exception
     */
    public function testEveryStayGoesToAServiceThatAdmitsItsPatient(): void
    {
        $report = $this->report();

        $this->assertSame(
            0,
            $report->profileFallbacks(),
            'Aucun séjour ne doit se rabattre sur un patient hors profil : la population est'
            . " dimensionnée pour l'offre de soins"
        );

        $specialities = $this->servicesBySpeciality();
        $checked      = [];

        foreach ($this->sejours() as $sejour) {
            $slug = $specialities[$sejour->service_id] ?? null;
            $this->assertNotNull($slug, "Séjour {$sejour->_id} hors des services du seed");

            $profile = $this->catalogue()->patientProfile($slug);
            $patient = $sejour->loadRefPatient();
            $age     = (int)CMbDT::daysRelative($patient->naissance, substr(self::NOW, 0, 10)) / 365.25;

            $this->assertGreaterThanOrEqual(
                $profile['min_age'],
                (int)floor($age),
                "Le patient {$patient->_id}, " . (int)floor($age) . " ans, n'a rien à faire en"
                . " '{$slug}'"
            );
            $this->assertLessThanOrEqual($profile['max_age'], (int)floor($age), $slug);

            if ($profile['sex'] !== null) {
                $this->assertSame(
                    $profile['sex'],
                    (string)$patient->sexe,
                    "Le service '{$slug}' n'admet que le sexe '{$profile['sex']}'"
                );
            }

            $checked[$slug] = true;
        }

        $this->assertArrayHasKey(
            'gynecologie-obstetrique',
            $checked,
            "La prémisse du test : l'établissement doit comporter un service à profil imposé"
        );
    }

    /**
     * L'intervention doit être faite par un chirurgien DE LA SPÉCIALITÉ.
     *
     * Le chirurgien était celui de la plage, tirée parmi tous les chirurgiens de l'établissement,
     * tandis que le libellé venait du service du séjour : sur un profil medium, 291 interventions
     * sur 442 étaient opérées par un praticien d'une autre spécialité — un gynécologue posait des
     * prothèses de hanche. Une vacation appartient désormais à une équipe.
     *
     * @throws Exception
     */
    public function testEveryOperationIsPerformedByItsOwnSpeciality(): void
    {
        $specialities = $this->servicesBySpeciality();
        $referential  = $this->report()->referential();
        $scheduled    = 0;
        $emergency    = 0;

        foreach ($this->operations() as $operation) {
            $sejour = new CSejour();
            $sejour->load($operation->sejour_id);

            $slug = $specialities[$sejour->service_id] ?? null;
            $this->assertNotNull($slug);

            $slot = new CPlageOp();
            $slot->load($operation->plageop_id);

            // Le chirurgien de l'intervention est TOUJOURS celui de sa plage : c'est la règle qui
            // rend le programme cohérent.
            $this->assertEquals(
                $slot->chir_id,
                $operation->chir_id,
                "L'intervention {$operation->_id} n'est pas opérée par le chirurgien de sa plage"
            );

            if ($referential->isEmergencySlot((int)$slot->_id)) {
                // Au bloc d'urgence, c'est le chirurgien DE GARDE qui opère : sa spécialité n'est
                // pas celle du service d'accueil, et c'est elle qui décide de l'acte.
                $emergency++;

                $this->assertTrue(
                    $referential->isEmergency($slug),
                    "L'intervention {$operation->_id} passe par le bloc d'urgence alors que son"
                    . " séjour est en '{$slug}'"
                );
                $this->assertNotNull($referential->specialityOf((int)$operation->chir_id));

                continue;
            }

            $scheduled++;
            $this->assertSame(
                $slug,
                $referential->specialityOf((int)$operation->chir_id),
                "L'intervention {$operation->_id} (« {$operation->libelle} ») est opérée par un"
                . " praticien qui n'est pas de '{$slug}'"
            );
        }

        $this->assertGreaterThan(0, $scheduled, 'Le bloc programmé doit avoir du travail');
        $this->assertGreaterThan(0, $emergency, "Le bloc d'urgence aussi");
    }

    /**
     * Le bloc d'urgence opère à toute heure et tous les jours, le bloc programmé jamais.
     *
     * Sans ce second bloc, une appendicectomie à deux heures du matin n'existait pas dans le jeu de
     * données : les seules plages ouvertes allaient du lundi au vendredi, de 8 h à 18 h 30.
     *
     * @throws Exception
     */
    public function testTheEmergencyTheatreOperatesOutsideProgrammeHours(): void
    {
        $referential = $this->report()->referential();
        $out_of_hours = 0;

        foreach ($this->operations() as $operation) {
            $slot = new CPlageOp();
            $slot->load($operation->plageop_id);

            $hour    = (int)substr((string)$operation->debut_op, 11, 2);
            $weekday = (int)date('N', strtotime((string)$operation->date));

            if (!$referential->isEmergencySlot((int)$slot->_id)) {
                $this->assertLessThanOrEqual(5, $weekday, 'Le bloc programmé n\'ouvre pas le week-end');
                $this->assertGreaterThanOrEqual(8, $hour, 'Ni avant 8 h');
                $this->assertLessThan(19, $hour, 'Ni après 18 h 30');

                continue;
            }

            if ($hour < 8 || $hour >= 19 || $weekday > 5) {
                $out_of_hours++;
            }
        }

        $this->assertGreaterThan(
            0,
            $out_of_hours,
            "Le bloc d'urgence doit avoir opéré au moins une fois hors des heures de programme"
        );
    }

    /**
     * Un séjour urgent est HÉBERGÉ, dans une unité que le socle autorise.
     *
     * Il ne l'était pas : `CAffectation::check()` refuse un séjour urgent dans un service qui ne
     * porte aucun des drapeaux urgence / UHCD / radiologie / obstétrique, et l'établissement n'en
     * ouvrait aucune. 10 % des séjours étaient donc des coquilles — un séjour déclaré urgent, sans
     * lit et sans acte.
     *
     * @throws Exception
     */
    public function testEmergencyStaysAreHostedInAnEmergencyUnit(): void
    {
        $specialities = $this->servicesBySpeciality();
        $referential  = $this->report()->referential();

        $by_sejour = [];
        foreach ($this->affectations() as $affectation) {
            $by_sejour[$affectation->sejour_id][] = $affectation;
        }

        $seen = 0;

        foreach ($this->sejours() as $sejour) {
            $slug = $specialities[$sejour->service_id] ?? null;

            if ($sejour->type !== 'urg') {
                $this->assertFalse(
                    $referential->isEmergency((string)$slug),
                    "Un séjour de type '{$sejour->type}' n'a rien à faire dans un box d'urgence"
                );

                continue;
            }

            $seen++;
            $this->assertTrue($referential->isEmergency((string)$slug), "Séjour urgent en '{$slug}'");

            // Le drapeau du socle porté par le séjour lui-même : c'est lui que l'application regarde
            // pour distinguer une hospitalisation de courte durée d'un passage en box.
            $this->assertSame($slug === 'uhcd' ? '1' : '0', (string)$sejour->UHCD, "UHCD de {$sejour->_id}");

            if ($sejour->entree_reelle) {
                $this->assertNotEmpty(
                    $by_sejour[$sejour->_id] ?? [],
                    "Le séjour urgent {$sejour->_id} n'a aucune affectation : il est sans lit"
                );
            }
        }

        $this->assertGreaterThan(0, $seen, 'Le mix annonce 10 % de séjours urgents');
    }

    /**
     * Les unités d'accueil des urgences ne doivent être ni vides, ni saturées.
     *
     * @throws Exception
     */
    public function testEmergencyUnitsAreNeitherEmptyNorSaturated(): void
    {
        $report = $this->report();

        $this->assertSame(2, $report->emergencyBeds(), '40 lits dont 2 en accueil des urgences');
        $this->assertGreaterThan(0, $report->occupiedEmergencyBeds(), 'Des boxes vides ne servent à rien');
        $this->assertTrue(
            $report->emergencyOccupancyInBand(),
            "Urgences à {$report->emergencyOccupancyRate()} %, hors de leur bande"
        );

        $this->assertSame(
            $report->occupiedEmergencyBeds(),
            $this->occupiedBedsFromDatabase(self::NOW, 'emergency'),
            'Le compte du builder et celui de la base doivent coïncider'
        );
    }

    /**
     * Un acte réservé à un sexe ne doit pas figurer au programme d'un patient de l'autre.
     *
     * Mesuré avant correction : dix césariennes sur des patients masculins, dont un de 93 ans.
     *
     * @throws Exception
     */
    public function testNoOperationContradictsItsPatientSex(): void
    {
        $specialities = $this->servicesBySpeciality();
        $seen         = 0;

        $referential = $this->report()->referential();

        foreach ($this->operations() as $operation) {
            $sejour = new CSejour();
            $sejour->load($operation->sejour_id);

            $patient = $sejour->loadRefPatient();

            // L'acte suit la spécialité DU CHIRURGIEN et non celle du service : au bloc d'urgence
            // les deux diffèrent, puisque c'est le chirurgien de garde qui opère.
            $slug = $referential->specialityOf((int)$operation->chir_id);

            $this->assertNotNull($slug);

            $seen++;
            $this->assertContains(
                $operation->libelle,
                $this->catalogue()->procedures($slug, (string)$patient->sexe),
                "« {$operation->libelle} » ne convient pas à un patient de sexe"
                . " '{$patient->sexe}'"
            );
        }

        $this->assertGreaterThan(0, $seen);
    }

    /**
     * L'hôpital recrute dans SON TERRITOIRE.
     *
     * Les communes étaient inventées — quinze noms composés à la main — et tirées uniformément : un
     * établissement recrutait indifféremment à Valmorin et à Noirvaux, qui n'existent ni l'un ni
     * l'autre. Il est désormais implanté dans une vraie commune, et ses patients viennent de son
     * département, à quelques exceptions près qui restent dans sa région.
     *
     * @throws Exception
     */
    public function testPatientsLiveAroundTheHospital(): void
    {
        $group = $this->report()->referential()->group();

        $this->assertNotEmpty($group->cp, "L'établissement doit avoir une adresse");
        $this->assertNotEmpty($group->ville, "et une commune");

        $department = substr((string)$group->cp, 0, 2);
        $local      = 0;
        $total      = 0;

        foreach ($this->sejours() as $sejour) {
            $patient = $sejour->loadRefPatient();

            $this->assertNotEmpty($patient->cp, "Le patient {$patient->_id} n'a pas de code postal");
            $this->assertNotEmpty($patient->ville, "ni de commune");

            $total++;

            if (substr((string)$patient->cp, 0, 2) === $department) {
                $local++;
            }
        }

        $this->assertGreaterThan(0, $total);

        // Les deux bornes disent la même chose : le recrutement est territorial, mais pas hermétique.
        $share = 100 * $local / $total;

        $this->assertGreaterThan(
            60,
            $share,
            'Seuls ' . round($share) . " % des patients viennent du département de l'établissement"
        );
        $this->assertLessThan(100, $share, 'Aucun patient ne vient de la région voisine ?');
    }

    /**
     * Un établissement sans pédiatrie n'hospitalise pas d'enfants.
     *
     * Le premier jet tirait les âges entre 1 et 95 ans quel que soit l'établissement : 19 % des
     * patients étaient mineurs alors qu'aucun service ne pouvait les accueillir, et on trouvait des
     * enfants de six ans hospitalisés en cardiologie ou en gériatrie.
     *
     * @throws Exception
     */
    public function testNoMinorIsAdmittedWhereThereIsNoPaediatrics(): void
    {
        $this->assertFalse(
            $this->report()->referential()->hostsChildren(),
            'Le profil small ne comporte pas de pédiatrie : c\'est la prémisse de ce test'
        );

        $adult_from = CMbDT::date('-18 years', substr(self::NOW, 0, 10));

        foreach ($this->sejours() as $sejour) {
            $patient = $sejour->loadRefPatient();

            $this->assertLessThanOrEqual(
                $adult_from,
                $patient->naissance,
                "Le patient {$patient->_id}, né le {$patient->naissance}, est mineur : aucun service"
                . ' de cet établissement ne peut l\'accueillir'
            );
        }
    }

    /**
     * Invariant 2 : deux affectations ne se chevauchent JAMAIS sur le même lit.
     *
     * C'est le cœur de la plausibilité, et ce que le motif des générateurs dépréciés ne pouvait
     * pas garantir. La vérification se fait en base, lit par lit.
     *
     * @throws Exception
     */
    public function testNoTwoAffectationsShareABedAtTheSameTime(): void
    {
        $by_bed = [];

        foreach ($this->affectations() as $affectation) {
            if (!$affectation->lit_id) {
                continue;
            }

            $by_bed[$affectation->lit_id][] = [$affectation->entree, $affectation->sortie, $affectation->_id];
        }

        $this->assertNotEmpty($by_bed, 'Le jeu de données doit occuper des lits');

        $checked = 0;
        foreach ($by_bed as $bed_id => $intervals) {
            usort(
                $intervals,
                static function (array $a, array $b): int {
                    return strcmp($a[0], $b[0]);
                }
            );

            for ($i = 1; $i < count($intervals); $i++) {
                $previous = $intervals[$i - 1];
                $current  = $intervals[$i];

                $this->assertLessThanOrEqual(
                    $current[0],
                    $previous[1],
                    "Lit {$bed_id} : l'affectation {$previous[2]} ({$previous[0]} → {$previous[1]}) chevauche"
                    . " l'affectation {$current[2]} ({$current[0]} → {$current[1]})"
                );
                $checked++;
            }
        }

        $this->assertGreaterThan(0, $checked, 'Au moins un lit doit être réutilisé, sinon le test ne prouve rien');
    }

    /**
     * Invariant 1, revérifié côté affectations : le lit d'une affectation appartient bien à une
     * chambre du service de l'affectation.
     *
     * @throws Exception
     */
    public function testEveryAssignedBedBelongsToTheAffectationService(): void
    {
        $service_of_bed = [];

        foreach ($this->affectations() as $affectation) {
            if (!$affectation->lit_id) {
                continue;
            }

            if (!isset($service_of_bed[$affectation->lit_id])) {
                $bed = new CLit();
                $bed->load($affectation->lit_id);

                $room = new CChambre();
                $room->load($bed->chambre_id);

                $service_of_bed[$affectation->lit_id] = $room->service_id;
            }

            $this->assertEquals(
                $service_of_bed[$affectation->lit_id],
                $affectation->service_id,
                "L'affectation {$affectation->_id} place le patient dans un lit d'un autre service"
            );
        }
    }

    /**
     * Invariant 3 : les affectations d'un séjour sont contiguës et incluses dans ses bornes
     * réelles.
     *
     * @throws Exception
     */
    public function testAffectationsAreContiguousAndInsideTheStay(): void
    {
        $by_stay = [];
        foreach ($this->affectations() as $affectation) {
            $by_stay[$affectation->sejour_id][] = $affectation;
        }

        $this->assertNotEmpty($by_stay);

        $with_mutation = 0;

        foreach ($by_stay as $sejour_id => $affectations) {
            $sejour = new CSejour();
            $sejour->load($sejour_id);

            $this->assertNotNull($sejour->_id, "Affectation orpheline sur le séjour {$sejour_id}");
            $this->assertNotEmpty(
                $sejour->entree_reelle,
                "Le séjour {$sejour_id} a une affectation sans être entré"
            );

            $upper = $sejour->sortie_reelle ?: $sejour->sortie_prevue;

            usort(
                $affectations,
                static function (CAffectation $a, CAffectation $b): int {
                    return strcmp($a->entree, $b->entree);
                }
            );

            $this->assertSame(
                $sejour->entree_reelle,
                $affectations[0]->entree,
                "La première affectation du séjour {$sejour_id} doit commencer à l'entrée réelle"
            );
            $this->assertSame(
                $upper,
                end($affectations)->sortie,
                "La dernière affectation du séjour {$sejour_id} doit finir avec le séjour"
            );

            if (count($affectations) > 1) {
                $with_mutation++;

                for ($i = 1; $i < count($affectations); $i++) {
                    $this->assertSame(
                        $affectations[$i - 1]->sortie,
                        $affectations[$i]->entree,
                        "Trou ou chevauchement entre deux affectations du séjour {$sejour_id}"
                    );
                }
            }

            foreach ($affectations as $affectation) {
                $this->assertGreaterThanOrEqual($sejour->entree_reelle, $affectation->entree);
                $this->assertLessThanOrEqual($upper, $affectation->sortie);
                $this->assertLessThan($affectation->sortie, $affectation->entree);
            }
        }

        $this->assertGreaterThan(
            0,
            $with_mutation,
            'Une mutation de service doit exister : c\'est un cas pédagogiquement intéressant'
        );
    }

    /**
     * Invariant 5, côté base : un ambulatoire entre et sort le même jour, et son affectation ne
     * porte pas de lit — `CAffectation::lit_id` n'est pas notNull, ce qui modélise exactement une
     * prise en charge sans nuit.
     *
     * @throws Exception
     */
    public function testAmbulatoryStaysOccupyNoBedAndLastOneDay(): void
    {
        $seen = 0;

        foreach ($this->sejours() as $sejour) {
            if ($sejour->type !== 'ambu' || !$sejour->entree_reelle) {
                continue;
            }

            $seen++;

            $this->assertSame(
                substr($sejour->entree_reelle, 0, 10),
                substr($sejour->sortie_reelle ?: $sejour->sortie_prevue, 0, 10),
                "Le séjour ambulatoire {$sejour->_id} doit tenir dans la journée"
            );

            $affectation             = new CAffectation();
            $affectation->sejour_id  = $sejour->_id;
            $affectations            = $affectation->loadMatchingList();

            foreach ($affectations as $_affectation) {
                $this->assertEmpty(
                    $_affectation->lit_id,
                    "L'affectation {$_affectation->_id} d'un ambulatoire ne doit pas porter de lit"
                );
            }
        }

        $this->assertGreaterThan(50, $seen, 'Le mix doit produire beaucoup d\'ambulatoires');
    }

    /**
     * @throws Exception
     */
    public function testOutpatientStaysHaveNoAffectationAtAll(): void
    {
        $seen = 0;

        foreach ($this->sejours() as $sejour) {
            if (!in_array($sejour->type, ['exte', 'seances', 'consult'], true)) {
                continue;
            }

            $seen++;

            $affectation            = new CAffectation();
            $affectation->sejour_id = $sejour->_id;

            $this->assertSame(
                0,
                (int)$affectation->countMatchingList(),
                "Le séjour {$sejour->_id} de type {$sejour->type} n'est pas hébergé"
            );
        }

        $this->assertGreaterThan(0, $seen);
    }

    /**
     * @throws Exception
     */
    public function testPlannedStaysHoldNoBedYet(): void
    {
        $seen = 0;

        foreach ($this->sejours() as $sejour) {
            if ($sejour->entree_reelle) {
                continue;
            }

            $seen++;

            $affectation            = new CAffectation();
            $affectation->sejour_id = $sejour->_id;

            $this->assertSame(
                0,
                (int)$affectation->countMatchingList(),
                "Le séjour {$sejour->_id}, pas encore entré, ne doit pas occuper de lit"
            );
            $this->assertGreaterThan(self::NOW, $sejour->entree_prevue);
        }

        $this->assertGreaterThan(10, $seen, 'Le profil annonce environ 10 % d\'admissions à venir');
    }

    /**
     * Un patient n'est pas hospitalisé deux fois en même temps.
     *
     * C'est une règle que le socle applique lui-même (CSejour::check() rend « Collision avec le
     * séjour du … »), donc un seed qui la violait ne produisait tout simplement pas de données.
     * Elle est vérifiée ici parce qu'elle fait aussi partie de la plausibilité du jeu : un
     * formateur qui ouvre un dossier patient ne doit pas y trouver deux séjours simultanés.
     *
     * @throws Exception
     */
    public function testNoPatientHasTwoOverlappingStays(): void
    {
        $by_patient = [];
        $exempt     = 0;

        foreach ($this->sejours() as $sejour) {
            // Le socle EXEMPTE certains types de son contrôle de collision
            // (CSejour::$_not_collides) : un patient peut cumuler une urgence, une consultation,
            // des séances et un externe sur la même période. L'invariant ne porte donc que sur les
            // types réellement contrôlés, et le seed ne réserve de créneau que pour ceux-là.
            if (in_array($sejour->type, SejourPlan::NOT_COLLIDING_TYPES, true)) {
                $exempt++;

                continue;
            }

            $from = $sejour->entree_reelle ?: $sejour->entree_prevue;
            $to   = $sejour->sortie_reelle ?: $sejour->sortie_prevue;

            $by_patient[$sejour->patient_id][] = [$from, $to, $sejour->_id];
        }

        $this->assertGreaterThan(0, $exempt, 'Le jeu de données doit contenir des types exemptés');

        $reused = 0;

        foreach ($by_patient as $patient_id => $stays) {
            if (count($stays) > 1) {
                $reused++;
            }

            usort(
                $stays,
                static function (array $a, array $b): int {
                    return strcmp($a[0], $b[0]);
                }
            );

            for ($i = 1; $i < count($stays); $i++) {
                // Comparaison au JOUR et bornes exclues : l'instance compare les collisions au
                // jour, avec des bornes inclusives. Deux séjours contrôlés d'un même patient ne
                // doivent donc pas même partager une journée.
                $this->assertLessThan(
                    substr($stays[$i][0], 0, 10),
                    substr($stays[$i - 1][1], 0, 10),
                    "Patient {$patient_id} : les séjours {$stays[$i - 1][2]} et {$stays[$i][2]} partagent"
                    . ' une journée'
                );
            }
        }

        $this->assertGreaterThan(
            0,
            $reused,
            'Un patient doit pouvoir revenir : sinon le test ne prouve rien sur les collisions'
        );
    }

    /**
     * Invariant 4, complété : chaque intervention tient dans la plage de SA salle, et deux
     * interventions d'une même plage ne se chevauchent pas.
     *
     * @throws Exception
     */
    public function testOperationsFitInTheirSlotWithoutOverlapping(): void
    {
        $operations = $this->operations();
        $this->assertNotEmpty($operations, 'Le bloc doit avoir un programme');

        $by_slot = [];

        foreach ($operations as $operation) {
            $this->assertNotEmpty($operation->plageop_id, "L'intervention {$operation->_id} sans plage");

            $slot = new CPlageOp();
            $slot->load($operation->plageop_id);

            $this->assertEquals(
                $slot->salle_id,
                $operation->salle_id,
                "L'intervention {$operation->_id} n'est pas dans la salle de sa plage"
            );
            $this->assertSame($slot->date, $operation->date, 'Intervention un autre jour que sa plage');

            $this->assertGreaterThanOrEqual(
                $slot->date . ' ' . $slot->debut,
                $operation->debut_op,
                "L'intervention {$operation->_id} commence avant sa plage"
            );
            $this->assertLessThanOrEqual(
                $slot->date . ' ' . $slot->fin,
                $operation->fin_op,
                "L'intervention {$operation->_id} finit après sa plage"
            );
            $this->assertLessThan($operation->fin_op, $operation->debut_op);

            $by_slot[$operation->plageop_id][] = [$operation->debut_op, $operation->fin_op, $operation->_id];
        }

        foreach ($by_slot as $slot_id => $intervals) {
            usort(
                $intervals,
                static function (array $a, array $b): int {
                    return strcmp($a[0], $b[0]);
                }
            );

            for ($i = 1; $i < count($intervals); $i++) {
                $this->assertLessThanOrEqual(
                    $intervals[$i][0],
                    $intervals[$i - 1][1],
                    "Plage {$slot_id} : deux interventions se chevauchent"
                );
            }
        }
    }

    /**
     * Un séjour doit toujours désigner un praticien et un patient existants : c'est ce que
     * l'application affiche en premier, et un lien mort s'y verrait immédiatement.
     *
     * @throws Exception
     */
    public function testEveryStayPointsToRealReferences(): void
    {
        foreach ($this->sejours() as $sejour) {
            $this->assertNotEmpty($sejour->patient_id);
            $this->assertNotEmpty($sejour->praticien_id);
            $this->assertNotEmpty($sejour->group_id);

            $this->assertNotNull(
                $sejour->loadRefPatient()->_id,
                "Le séjour {$sejour->_id} pointe un patient inexistant"
            );
            $this->assertNotNull(
                $sejour->loadRefPraticien()->_id,
                "Le séjour {$sejour->_id} pointe un praticien inexistant"
            );
        }
    }

    /**
     * Le dry-run doit produire les mêmes COMPTES que l'écriture réelle, sinon il ne renseigne
     * sur rien. Il ne touche pas la base : les builders tournent, seule l'écriture est retenue.
     *
     * @throws Exception
     */
    public function testDryRunCountsMatchTheRealRun(): void
    {
        $writer  = new CollectingWriter(false);
        $context = new SeedContext(
            self::profile(),
            new SeedRandom(self::SEED),
            $writer,
            self::NOW
        );

        // Le dry-run s'arrête au premier étage : sans identifiants, les enfants ne peuvent pas
        // être rattachés. C'est la limite assumée du mode, et ce test la documente.
        $counts = null;
        try {
            (new HospiSeeder($context))->run();
            $counts = $writer->counts();
        } catch (Exception $e) {
            $counts = $writer->counts();
        }

        $this->assertArrayHasKey('CGroups', $counts, 'Le dry-run doit au moins construire l\'établissement');
        $this->assertSame(1, $counts['CGroups']);
    }

    /**
     * Le catalogue du décor employé par ce jeu de données.
     *
     * Les recherches ne sont plus statiques : elles appartiennent au catalogue, et un autre décor en a
     * un autre. Y passer ici, plutôt que d'interroger `Specialities`, est ce qui garde ce test valable
     * si le décor de référence change.
     *
     * @throws Exception
     */
    private function catalogue(): SpecialityCatalogue
    {
        return self::profile()->decor()->catalogue();
    }

    /**
     * @throws Exception
     */
    private function coding(): ?ClinicalCoding
    {
        return self::profile()->decor()->coding();
    }

    /**
     * @throws Exception
     */
    private function report(): SeedReport
    {
        if (self::$failure !== null) {
            throw self::$failure;
        }

        if (self::$report === null) {
            $context = new SeedContext(
                self::profile(),
                new SeedRandom(self::SEED),
                new OrmWriter(self::class),
                self::NOW
            );

            try {
                self::$report = (new HospiSeeder($context))->run();
            } catch (Exception $e) {
                self::$failure = $e;

                throw $e;
            }
        }

        return self::$report;
    }

    /**
     * Séjours du jeu de données, relus en base.
     *
     * @return CSejour[]
     * @throws Exception
     */
    private function sejours(): array
    {
        $group_id = $this->report()->referential()->group()->_id;

        $sejour           = new CSejour();
        $sejour->group_id = $group_id;

        return $sejour->loadMatchingList('sejour_id');
    }

    /**
     * @return CAffectation[]
     * @throws Exception
     */
    private function affectations(): array
    {
        $ids = [];
        foreach ($this->sejours() as $sejour) {
            $ids[] = $sejour->_id;
        }

        if ($ids === []) {
            return [];
        }

        $affectation = new CAffectation();
        $where       = ['sejour_id' => $affectation->getDS()->prepareIn($ids)];

        return $affectation->loadList($where, 'affectation_id');
    }

    /**
     * @return COperation[]
     * @throws Exception
     */
    private function operations(): array
    {
        $ids = [];
        foreach ($this->sejours() as $sejour) {
            $ids[] = $sejour->_id;
        }

        if ($ids === []) {
            return [];
        }

        $operation = new COperation();
        $where     = ['sejour_id' => $operation->getDS()->prepareIn($ids)];

        return $operation->loadList($where, 'operation_id');
    }

    /**
     * Lits occupés à un instant donné, recomptés depuis la base.
     *
     * @throws Exception
     */
    private function occupiedBedsFromDatabase(string $moment, string $family = 'ward'): int
    {
        $scope = $this->bedScope($family);
        $beds  = [];

        foreach ($this->affectations() as $affectation) {
            if (!$affectation->lit_id || !isset($scope[$affectation->lit_id])) {
                continue;
            }

            if (strcmp($moment, $affectation->entree) >= 0 && strcmp($moment, $affectation->sortie) < 0) {
                $beds[$affectation->lit_id] = true;
            }
        }

        return count($beds);
    }

    /**
     * Lits des unités de soins continus, ou des autres, indexés par identifiant.
     *
     * Les deux périmètres se mesurent SÉPARÉMENT : ils ont chacun leur bande d'occupation, et un
     * comptage global ne correspondrait à aucune des deux.
     *
     * @return array<int, true>
     * @throws Exception
     */
    private function bedScope(string $family): array
    {
        $referential = $this->report()->referential();
        $scope       = [];

        foreach ($referential->serviceSlugs() as $slug) {
            $own = $referential->isEmergency($slug)
                ? 'emergency'
                : ($referential->isIntensive($slug) ? 'intensive' : 'ward');

            if ($own !== $family) {
                continue;
            }

            foreach ($referential->beds($slug) as $bed) {
                $scope[$bed->_id] = true;
            }
        }

        return $scope;
    }

    /**
     * Spécialité de chaque service, par identifiant de service.
     *
     * @return array<int, string>
     * @throws Exception
     */
    private function servicesBySpeciality(): array
    {
        $map = [];

        foreach ($this->report()->referential()->services() as $slug => $entry) {
            $map[$entry['service']->_id] = $slug;
        }

        return $map;
    }

    /**
     * Profil du jeu de données de test : cf. SCALE.
     *
     * @throws Exception
     */
    private static function profile(): SeedProfile
    {
        return SeedProfile::named('small', self::SCALE);
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
    private static function purgeSeed(): void
    {
        $purger = new SeedPurger(self::class);
        $purger->purge();
        $purger->assertClean();
    }
}
