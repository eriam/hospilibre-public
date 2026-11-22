<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

use Hospilibre\Bloc\CPlageOp;
use Hospilibre\Ccam\CCodageCCAM;
use Hospilibre\Ccam\CCodeCCAM;
use Hospilibre\Hospi\CAffectation;
use Hospilibre\Hospi\CObservationMedicale;
use Hospilibre\Hospi\CTransmissionMedicale;
use Hospilibre\Patients\CPatient;
use Hospilibre\PlanningOp\COperation;
use Hospilibre\PlanningOp\CSejour;
use Hospilibre\SalleOp\CActeCCAM;
use Ox\Cli\Seed\Decor\ClinicalCoding;
use Ox\Core\CMbDT;

/**
 * Séjours, affectations et interventions, à partir des scénarios décidés par SejourPlanner.
 *
 * TROIS allocateurs, et c'est le même outil : `BedAllocator` maintient par clé une liste
 * d'intervalles sans chevauchement. La clé est tantôt le lit, tantôt la PLAGE opératoire, tantôt
 * le PATIENT. Deux interventions d'une même plage ne doivent pas se chevaucher exactement comme
 * deux patients d'un même lit, ni comme deux séjours d'un même patient — le besoin est identique,
 * l'outil aussi.
 *
 * ORDRE DE PLACEMENT : les séjours EN COURS passent d'abord. Ce sont eux qui portent l'occupation
 * instantanée visée, donc leur lit n'est pas négociable ; les séjours clos, eux, peuvent se
 * rabattre sur une affectation de service si tous les lits de la période sont pris.
 */
final class SejourBuilder
{


    /** Pas de placement dans une plage, en minutes : un programme opératoire n'est pas à la minute. */
    private const OPERATION_STEP_MINUTES = 15;

    /** Type de séjour de l'hospitalisation de JOUR, celui qui restreint les actes possibles. */
    private const DAY_CASE_TYPE = 'ambu';

    /** Activités CCAM : le geste du chirurgien, et l'anesthésie. */
    private const CCAM_ACTIVITY_PROCEDURE = 1;

    private const CCAM_ACTIVITY_ANAESTHESIA = 4;

    /** @var SeedContext */
    private $context;

    /** @var Referential */
    private $referential;

    /** @var BedAllocator Occupation des lits */
    private $beds;

    /** @var BedAllocator Occupation du temps opératoire, par plage */
    private $theatre;

    /**
     * @var BedAllocator Périodes déjà occupées par chaque PATIENT.
     *
     * Troisième usage du même outil, et pour la même raison : CSejour::check() refuse deux
     * séjours qui se chevauchent pour un même patient (« Collision avec le séjour du … »). C'est
     * une règle clinique juste — on n'est pas hospitalisé deux fois en même temps — et tirer le
     * patient au hasard la violait dès que 300 séjours se partageaient 150 patients. Deux séjours
     * consécutifs restent possibles : les intervalles sont semi-ouverts.
     */
    private $patients_busy;

    /**
     * @var array<string, array<string, CPlageOp[]>> plages par jour, puis par SPÉCIALITÉ qui les tient.
     *
     * Une vacation appartient à une équipe : une prothèse de hanche ne s'inscrit pas dans la vacation
     * des gynécologues. Sans ce second niveau, l'intervention prenait le chirurgien de la plage tout
     * en portant un libellé venu du service du séjour, et 291 interventions sur 442 étaient opérées
     * par un praticien d'une autre spécialité.
     */
    private $slots_by_day = [];

    /** @var int Séjours rabattus sur une affectation de service faute de lit libre */
    private $bed_fallbacks = 0;

    /** @var int Interventions non programmées faute de plage disponible ce jour-là */
    private $unscheduled_operations = 0;

    /**
     * @var int Interventions abandonnées PAR LE PLANIFICATEUR, faute de jour atteignable.
     *
     * Distinct du compteur ci-dessus, et pourtant du même ordre : les deux sont des interventions
     * décidées puis perdues. Ne rapporter que celles que le builder n'arrive pas à écrire laissait
     * les autres — 36 sur 478 d'un profil medium — disparaître en silence.
     */
    private $dropped_operations = 0;

    /**
     * @var array<string, CPatient[]> Patients éligibles, par profil d'admission.
     *
     * La clé est le profil lui-même et non le service : deux services qui admettent les mêmes
     * patients partagent la même liste, et le filtrage n'est fait qu'une fois par profil distinct.
     */
    private $eligible = [];

    /** @var int Séjours confiés à un patient hors du profil d'âge ou de sexe du service */
    private $profile_fallbacks = 0;

    /**
     * @var array<int, array{patient: CPatient, service: string, motif_sejour: string}>
     *
     * Ancrés encore sans séjour. Vidé au fur et à mesure du placement : ce qui reste à la fin est
     * un échec à rapporter, pas un détail — un ancré sans séjour est le seul dossier vide de
     * l'établissement, et c'est celui qu'une consigne d'exercice cite.
     */
    private $anchors_pending = [];

    /** @var array<string, int> Incidents peropératoires tirés, par type. */
    private $incidents = [];

    /** @var array<string, int> Écrits du dossier de soins produits. */
    private $care_records = ['observations' => 0, 'transmissions' => 0];

    /** @var array<int, array{service: string, motif_sejour: string}> Clinique imposée, par patient */
    private $anchor_clinical = [];

    /** @var int Ancrés placés dans un service qui n'admet pas leur profil, parce qu'imposé */
    private $anchor_profile_overrides = 0;

    /** @var int Ancrés qu'AUCUNE unité ouverte n'admet, admis quand même */
    private $anchors_without_matching_unit = 0;

    /** @var int|null Plan dont les bornes de réservation sont mémorisées */
    private $window_plan;

    /** @var array{0: string, 1: string} Bornes mémorisées, voir stayWindow() */
    private $window_bounds = ['', ''];

    /** @var array<string, int> Interventions écrites, par bloc : programmé et urgence */
    private $operations = ['programme' => 0, 'urgence' => 0];

    /** @var AnaesthesiaBuilder|null Le parcours anesthésique, instancié à la première intervention. */
    private $anaesthesia;

    /** @var CompteRenduBuilder|null Les comptes rendus, instanciés à la première intervention. */
    private $reports;

    /** @var DocumentBuilder|null Les pièces jointes, instanciées au premier séjour. */
    private $documents;

    /** @var VitalsBuilder|null Les constantes, instanciées au premier séjour. */
    private $vitals;

    /** @var array<string, string[]> Codes déclarés par spécialité, mémoïsés : le décor ne change pas. */
    private $speciality_seed_codes = [];

    /** @var int Séjours dont le DP vient de la spécialité et non d'une correspondance d'acte. */
    private $speciality_diagnoses = 0;

    /** @var int Interventions écrites sans cotation CCAM, faute d'acte codé dans le décor */
    private $uncoded_operations = 0;

    /** @var int Actes lourds posés sur un séjour de jour, faute d'acte ambulatoire dans la spécialité */
    private $non_ambulatory_operations = 0;

    /** @var array<string, int> Actes CCAM écrits, par activité : 1 le geste, 4 l'anesthésie */
    private $ccam_actes = ['geste' => 0, 'anesthesie' => 0];

    /**
     * @var bool Le dictionnaire CCAM répond-il ?
     *
     * Écrire `codes_ccam` n'en demande pas ; écrire un CActeCCAM si — voir CcamDictionary.
     */
    private $ccam_available;

    /** @var array<string, bool> L'acte admet-il l'activité 4 ? Mémoïsé par code : il n'y en a qu'une poignée. */
    private $anaesthesia_activity = [];

    /** @var int Séjours portant un diagnostic principal */
    private $coded_sejours = 0;

    /** @var int Séjours sans diagnostic parce que leur unité n'en déclare aucun */
    private $uncoded_sejours = 0;

    /**
     * @var bool Le dictionnaire CIM-10 répond-il sur cette instance ?
     *
     * Sondé UNE FOIS, à la construction : sans lui, écrire un `CSejour::DP` interrompt le processus
     * — voir Cim10Dictionary. Les séjours sont alors écrits sans diagnostic, et le rapport le dit.
     */
    private $diagnoses_available;

    public function __construct(SeedContext $context, Referential $referential)
    {
        $this->context     = $context;
        $this->referential = $referential;
        $this->diagnoses_available = Cim10Dictionary::isAvailable();
        $this->ccam_available      = CcamDictionary::isAvailable();
        $this->beds          = new BedAllocator();
        $this->theatre       = new BedAllocator();
        $this->patients_busy = new BedAllocator();
    }

    /**
     * Déclare les patients d'identité imposée, pour qu'ils soient servis EN PREMIER.
     *
     * Sans cette priorité, un ancré n'aurait qu'une chance statistique d'obtenir un séjour — il est
     * un patient parmi des milliers — et le dossier que le scénario cite nommément serait
     * précisément celui qui risque d'être vide. La priorité ne lui donne pas un traitement de
     * faveur clinique : il reçoit un séjour du même mix que les autres, simplement le premier qui
     * lui convient.
     *
     * @param array<int, array{patient: CPatient, service: string, motif_sejour: string}> $anchored
     */
    public function anchor(array $anchored): void
    {
        $this->anchors_pending = [];
        $this->anchor_clinical = [];

        foreach ($anchored as $anchor) {
            $id = (int)$anchor['patient']->_id;

            // UN ANCRÉ QU'AUCUNE UNITÉ OUVERTE N'ADMET doit quand même obtenir un séjour, et le
            // savoir dès maintenant évite de le découvrir à la fin sous la forme d'un dossier vide.
            // Le cas arrive pour de vrai : la pédiatrie est au rang 27 du catalogue, donc un profil
            // de douze ou vingt-deux unités ne l'ouvre pas, et un scénario peut parfaitement citer un
            // enfant. L'alternative — le laisser sans parcours — serait pire : c'est justement le
            // dossier que la consigne d'exercice nomme.
            $anchor['no_matching_unit'] = !$this->fitsAnyOpenUnit($anchor['patient']);

            if ($anchor['no_matching_unit']) {
                $this->anchors_without_matching_unit++;
            }

            $this->anchors_pending[$id] = $anchor;
            $this->anchor_clinical[$id] = [
                'service'      => $anchor['service'],
                'motif_sejour' => $anchor['motif_sejour'],
            ];
        }
    }

    /**
     * Une unité ouverte admet-elle ce patient ?
     *
     * @throws SeedException
     */
    private function fitsAnyOpenUnit(CPatient $patient): bool
    {
        foreach ($this->referential->serviceSlugs() as $slug) {
            if ($this->filterPatients([$patient], $this->referential->patientProfile($slug)) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ancrés restés sans séjour. Doit valoir zéro.
     *
     * @return CPatient[]
     */
    public function unplacedAnchors(): array
    {
        return array_column($this->anchors_pending, 'patient');
    }

    /** Ancrés admis dans un service qui n'admet pas leur profil, parce que le fichier l'imposait. */
    public function anchorProfileOverrides(): int
    {
        return $this->anchor_profile_overrides;
    }

    /**
     * Ancrés qu'aucune unité ouverte par le décor n'admet — un enfant sans pédiatrie, typiquement.
     *
     * Ils sont admis quand même, dans l'unité qui se présente. Ce n'est pas satisfaisant, et c'est
     * pour cela que le chiffre est rapporté : le remède est de choisir un profil qui ouvre l'unité
     * qu'il faut, pas de laisser le dossier vide.
     */
    public function anchorsWithoutMatchingUnit(): int
    {
        return $this->anchors_without_matching_unit;
    }

    /**
     * Construit et écrit tous les séjours, et rend LEUR NOMBRE.
     *
     * Un nombre, et non la liste. Les retenir coûtait la mémoire du processus pour rien : le seul
     * usage du tableau rendu était un `count()`, et un `CSejour` complet pèse quelques dizaines de
     * kilo-octets une fois ses références chargées par `store()`. Sur le profil `medium` — vingt
     * mille séjours — le processus dépassait la limite de 1 Go et mourait sur un
     * « Allowed memory size exhausted » APRÈS avoir écrit les patients, donc en laissant une base à
     * moitié peuplée. Les tests, eux, relisent les séjours en base : c'est plus fidèle, puisque
     * c'est ce que l'application verra.
     *
     * @param CPatient[] $patients
     *
     * @throws SeedException
     */
    public function build(array $patients): int
    {
        if ($patients === []) {
            throw new SeedException('Aucun patient : impossible de créer des séjours');
        }

        $this->indexSlots();

        // Les jours opératoires sont ceux des plages RÉELLEMENT créées : le planificateur cale
        // les séjours chirurgicaux dessus, au lieu de les placer au hasard puis de constater qu'un
        // tiers des interventions tombent un jour où le bloc est fermé.
        $planner = new SejourPlanner(
            $this->context,
            $this->describeServices(),
            $this->theatreCapacity()
        );
        $plans = $planner->plan();

        $this->dropped_operations = $planner->droppedOperations();

        // Les séjours en cours d'abord : voir l'ordre de placement en tête de classe.
        $ordered = [];
        foreach ($plans as $plan) {
            if ($plan->isCurrent()) {
                $ordered[] = $plan;
            }
        }
        foreach ($plans as $plan) {
            if (!$plan->isCurrent()) {
                $ordered[] = $plan;
            }
        }

        $written = 0;

        foreach ($ordered as $plan) {
            $patient = $this->pickFreePatient($plan, $patients);

            $this->buildOne($plan, $patient);
            $written++;
        }

        $this->context->writer()->flush();

        return $written;
    }

    /**
     * Un patient qui n'est pas déjà hospitalisé sur la période du séjour.
     *
     * L'intervalle retenu est la période EFFECTIVE du séjour — dates réelles si elles existent,
     * prévues sinon — parce que c'est sur elle que porte le contrôle de collision du socle.
     *
     * @param CPatient[] $patients
     *
     * @throws SeedException
     */
    private function pickFreePatient(SejourPlan $plan, array $patients): CPatient
    {
        $random = $this->context->stream('patient-du-sejour');

        // LES ANCRÉS D'ABORD, tant qu'il en reste sans séjour : voir anchor().
        $anchored = $this->pickPendingAnchor($plan);

        if ($anchored !== null) {
            return $anchored;
        }

        // Chaque service a son profil de patients : une pédiatrie reçoit des mineurs, une gériatrie
        // des personnes âgées, une obstétrique des femmes en âge de l'être. Sans ce filtre, le
        // tirage ne regardait que la disponibilité du patient — jamais son âge ni son sexe.
        $patients = $this->patientsFor($plan, $patients);

        // Types exemptés par le socle : aucun créneau à réserver, n'importe quel patient convient.
        if (!$plan->collidesWithOtherStays()) {
            return $random->pick($patients);
        }

        foreach ($random->shuffle($patients) as $patient) {
            if ($this->reservePatient($plan, $patient)) {
                return $patient;
            }
        }

        [$entry, $exit] = $this->stayDays($plan);

        throw new SeedException(
            "Aucun patient disponible du {$entry} au {$exit} : le profil demande trop de séjours"
            . ' simultanés pour sa population, sachant qu\'un patient ne peut avoir qu\'un séjour'
            . ' par journée. Augmenter --patients.'
        );
    }

    /**
     * Un ancré encore sans séjour, si l'un d'eux convient à ce plan.
     *
     * Le service imposé par le fichier, quand il l'est, est RESPECTÉ MÊME s'il contredit le profil
     * d'admission du service : le scénario a tranché, et lui désobéir en silence serait pire que de
     * l'appliquer. Le cas est compté, pour que le rapport le dise. Sans service imposé, en
     * revanche, l'ancré reste dans le profil d'admission — sinon un homme de quarante ans
     * atterrirait en obstétrique, et c'est le genre de détail qui ressemble à un indice.
     *
     * @throws SeedException
     */
    private function pickPendingAnchor(SejourPlan $plan): ?CPatient
    {
        if ($this->anchors_pending === []) {
            return null;
        }

        $profile = $this->referential->patientProfile($plan->service());

        foreach ($this->anchors_pending as $id => $anchor) {
            $imposed = $anchor['service'];

            if ($imposed !== '' && $imposed !== $plan->service()) {
                continue;
            }

            $patient     = $anchor['patient'];
            $off_profile = $this->filterPatients([$patient], $profile) === [];

            // Sans service imposé, l'ancré reste dans le profil d'admission — SAUF si aucune unité
            // ouverte ne l'admet, auquel cas exiger le profil le condamnerait à n'avoir aucun séjour.
            if ($imposed === '' && $off_profile && !$anchor['no_matching_unit']) {
                continue;
            }

            if (!$this->reservePatient($plan, $patient)) {
                continue;
            }

            // Compté ici SEULEMENT quand le fichier imposait le service : l'ancré qu'aucune unité
            // n'admet est déjà compté à la déclaration, et lui attribuer cette raison-ci ferait dire
            // au rapport que le fichier a imposé une unité alors qu'il n'en cite aucune.
            if ($off_profile && $imposed !== '') {
                $this->anchor_profile_overrides++;
            }

            unset($this->anchors_pending[$id]);

            return $patient;
        }

        return null;
    }

    /**
     * Réserve le patient sur la période du séjour, ou rend false s'il y est déjà hospitalisé.
     *
     * RÉSERVATION À LA JOURNÉE, et non à l'heure. L'instance compare les collisions de séjours
     * au jour (configuration « dPplanningOp CSejour check_collisions = date ») et avec des
     * bornes INCLUSIVES (CMbRange::collides(..., $permissive = false)) : deux séjours d'un même
     * patient qui se touchent, ou qui partagent seulement une journée, sont donc refusés. Une
     * réservation à l'heure laissait passer deux ambulatoires le même jour, que le socle
     * rejetait ensuite.
     */
    private function reservePatient(SejourPlan $plan, CPatient $patient): bool
    {
        // Type exempté par le socle : rien à réserver, et donc rien qui puisse refuser.
        if (!$plan->collidesWithOtherStays()) {
            return true;
        }

        [$from, $to] = $this->stayWindow($plan);

        $key = 'patient:' . $patient->_id;

        if (!$this->patients_busy->isFree($key, $from, $to)) {
            return false;
        }

        $this->patients_busy->allocate($key, $from, $to);

        return true;
    }

    /**
     * Premier et dernier JOUR du séjour.
     *
     * La période retenue est la période EFFECTIVE — dates réelles si elles existent, prévues
     * sinon — parce que c'est sur elle que porte le contrôle de collision du socle.
     *
     * @return array{0: string, 1: string}
     */
    private function stayDays(SejourPlan $plan): array
    {
        return [
            substr($plan->entreeReelle() ?? $plan->entreePrevue(), 0, 10),
            substr($plan->sortieReelle() ?? $plan->sortiePrevue(), 0, 10),
        ];
    }

    /**
     * Bornes de réservation du patient, MÉMORISÉES pour le plan courant.
     *
     * Une seule entrée de mémo, et non une par plan : chaque plan n'est visité qu'une fois, mais il
     * l'est pour plusieurs patients candidats. Sans mémo, le `CMbDT::date('+1 day', …)` était refait
     * à chaque candidat essayé, alors qu'il ne dépend que du plan.
     *
     * @return array{0: string, 1: string}
     */
    private function stayWindow(SejourPlan $plan): array
    {
        $id = spl_object_id($plan);

        if ($this->window_plan !== $id) {
            [$entry, $exit] = $this->stayDays($plan);

            $this->window_plan  = $id;
            $this->window_bounds = [
                $entry . ' 00:00:00',
                CMbDT::date('+1 day', $exit) . ' 00:00:00',
            ];
        }

        return $this->window_bounds;
    }

    /**
     * Patients que le service du séjour peut admettre.
     *
     * La liste est calculée une fois par profil distinct : refiltrer cent mille patients à chaque
     * séjour coûterait plus cher que tout le reste du placement.
     *
     * @param CPatient[] $patients
     *
     * @return CPatient[]
     * @throws SeedException
     */
    private function patientsFor(SejourPlan $plan, array $patients): array
    {
        $profile = $this->referential->patientProfile($plan->service());
        $key     = $profile['min_age'] . '-' . $profile['max_age'] . '-' . ($profile['sex'] ?? 'tous');

        if (!isset($this->eligible[$key])) {
            $this->eligible[$key] = $this->filterPatients($patients, $profile);
        }

        if ($this->eligible[$key] === []) {
            // Repli sur la population entière plutôt que d'échouer : une population qui ne contient
            // personne du profil demandé est une incohérence d'échelle, pas une raison d'interrompre
            // — mais elle est COMPTÉE, sans quoi le jeu de données mentirait en silence.
            $this->profile_fallbacks++;

            return $patients;
        }

        return $this->eligible[$key];
    }

    /**
     * @param CPatient[]                                             $patients
     * @param array{min_age: int, max_age: int, sex: string|null} $profile
     *
     * @return CPatient[]
     * @throws SeedException
     */
    private function filterPatients(array $patients, array $profile): array
    {
        $today = $this->context->today();

        // Bornes de DATE DE NAISSANCE et non d'âge : une comparaison de chaînes de dates SQL évite
        // de recalculer un âge par patient, et elle est exacte au jour près.
        $youngest = CMbDT::date('-' . $profile['min_age'] . ' years', $today);
        $oldest   = CMbDT::date('-' . ($profile['max_age'] + 1) . ' years', $today);

        $eligible = [];

        foreach ($patients as $patient) {
            $birth = (string)$patient->naissance;

            if (strcmp($birth, $youngest) > 0 || strcmp($birth, $oldest) <= 0) {
                continue;
            }

            if ($profile['sex'] !== null && (string)$patient->sexe !== $profile['sex']) {
                continue;
            }

            $eligible[] = $patient;
        }

        return $eligible;
    }

    /** Séjours confiés à un patient hors du profil d'admission de leur service. */
    public function profileFallbacks(): int
    {
        return $this->profile_fallbacks;
    }

    /**
     * Interventions écrites, ventilées entre le programme et la garde.
     *
     * @return array<string, int>
     */
    public function operations(): array
    {
        return $this->operations;
    }

    /**
     * Le parcours anesthésique écrit : plages, consultations, dossiers, visites.
     *
     * RAPPORTÉ PARCE QU'IL EST ÉCRIT. Un semis qui produit 336 consultations d'anesthésie sans le
     * dire les rend invérifiables : c'est exactement ainsi qu'un `operation_id` resté nul sur les
     * 336 dossiers est passé inaperçu jusqu'à une requête faite à la main.
     *
     * @return array<string, int>
     */
    public function anaesthesia(): array
    {
        // ⚠ LES MÊMES CLÉS QUE `AnaesthesiaBuilder::counters()`, y compris celles du compte rendu de
        // consultation qu'il fusionne. Une clé oubliée ici ne ferait pas d'erreur : elle ferait
        // DISPARAÎTRE une ligne du rapport quand aucune intervention n'a été produite, c'est-à-dire
        // exactement dans le cas où le lecteur a besoin de voir le zéro.
        return $this->anaesthesia === null
            ? [
                'consultations'          => 0,
                'visites'                => 0,
                'plages'                 => 0,
                'dossiers'               => 0,
                'comptes_rendus'         => 0,
                'consultations_redigees' => 0,
            ]
            : $this->anaesthesia->counters();
    }

    /**
     * Codage produit : diagnostics posés, diagnostics manquants, interventions sans cotation.
     *
     * @return array<string, int|bool>
     */
    public function coding(): array
    {
        return [
            'dictionnaire'         => $this->diagnoses_available,
            'sejours_codes'        => $this->coded_sejours,
            'sejours_sans_code'    => $this->uncoded_sejours,
            'actes_sans_cotation'  => $this->uncoded_operations,
            'actes_hors_journee'   => $this->non_ambulatory_operations,
            'dictionnaire_ccam'    => $this->ccam_available,
            'actes_geste'          => $this->ccam_actes['geste'],
            'actes_anesthesie'     => $this->ccam_actes['anesthesie'],
            'dp_de_specialite'     => $this->speciality_diagnoses,
            'vivier_ccam'          => $this->poolSize(),
            'dictionnaire_actes'   => CcamActPool::dictionarySize(),
        ];
    }

    public function bedFallbacks(): int
    {
        return $this->bed_fallbacks;
    }

    /** Interventions décidées puis perdues, que ce soit à la planification ou à l'écriture. */
    public function unscheduledOperations(): int
    {
        return $this->unscheduled_operations + $this->dropped_operations;
    }

    /** Occupation instantanée des lits d'hospitalisation complète, sur les lits alloués. */
    public function occupiedBedsAt(string $moment): int
    {
        return $this->beds->occupiedAt($moment, $this->bedScope('ward'));
    }

    /**
     * Occupation instantanée des unités d'ACCUEIL DES URGENCES.
     *
     * Troisième périmètre de mesure, et pour la même raison que les deux autres : ces unités ont
     * leur propre bande, plus basse encore. Un box occupé n'est pas disponible pour le suivant.
     *
     * @throws SeedException
     */
    public function occupiedEmergencyBedsAt(string $moment): int
    {
        return $this->beds->occupiedAt($moment, $this->bedScope('emergency'));
    }

    /**
     * Occupation instantanée des SOINS CONTINUS.
     *
     * Mesurée à part : ces unités ont leur propre bande, plus basse. Les mélanger donnait un taux
     * d'occupation global qui ne correspondait à aucune des deux bandes.
     *
     * @throws SeedException
     */
    public function occupiedIcuBedsAt(string $moment): int
    {
        return $this->beds->occupiedAt($moment, $this->bedScope('intensive'));
    }

    /**
     * Identifiants de lits d'une famille d'unités : 'ward', 'intensive' ou 'emergency'.
     *
     * @return string[]
     * @throws SeedException
     */
    private function bedScope(string $family): array
    {
        $ids = [];

        foreach ($this->referential->serviceSlugs() as $slug) {
            if ($this->familyOf($slug) !== $family) {
                continue;
            }

            foreach ($this->bedIds($slug) as $id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Famille d'une unité. Les trois sont exclusives, et chacune a sa bande d'occupation : les
     * confondre donnait un taux qui ne correspondait à aucune des trois.
     */
    private function familyOf(string $slug): string
    {
        if ($this->referential->isEmergency($slug)) {
            return 'emergency';
        }

        return $this->referential->isIntensive($slug) ? 'intensive' : 'ward';
    }

    /**
     * @return array<string, array{beds: int, intensive: bool, surgical: bool, emergency: bool}>
     */
    private function describeServices(): array
    {
        $services = [];

        foreach ($this->referential->services() as $slug => $entry) {
            $services[$slug] = [
                'beds'      => count($entry['beds']),
                'intensive' => $this->referential->isIntensive($slug),
                'surgical'  => $this->referential->isSurgical($slug),
                'emergency' => $this->referential->isEmergency($slug),
            ];
        }

        return $services;
    }

    private function indexSlots(): void
    {
        foreach ($this->referential->slots() as $slot) {
            // Les vacations du bloc d'urgence forment une réserve COMMUNE : elles appartiennent à
            // l'équipe de garde, et toute intervention en urgence peut s'y inscrire quelle que soit
            // la spécialité qui l'opère. Celles du bloc programmé appartiennent à une spécialité.
            $pool = $this->referential->isEmergencySlot((int)$slot->_id)
                ? SejourPlan::EMERGENCY_POOL
                : $this->referential->specialityOf((int)$slot->chir_id);

            if ($pool === null) {
                continue;
            }

            $this->slots_by_day[$slot->date][$pool][] = $slot;
        }
    }

    /**
     * Capacité opératoire de chaque jour, en nombre d'interventions.
     *
     * Le planificateur en a besoin pour répartir : lui donner la seule liste des jours ouvrés
     * laissait plusieurs séjours se donner rendez-vous le même jour.
     *
     * Le plafond retenu est DÉLIBÉRÉMENT PRUDENT : deux interventions par plage, ce que la durée
     * maximale garantit de pouvoir tenir. Le calculer sur les minutes réelles et la durée moyenne
     * en donnait trois, et c'était pire : le planificateur laissait passer une troisième
     * intervention qu'une vacation chargée ne pouvait plus prendre, et l'écriture la perdait
     * définitivement — alors qu'un refus à la planification, lui, la fait reporter à un autre jour.
     * Mesuré sur `medium` : 39 interventions perdues contre 30.
     *
     * @return array<string, array<string, int>>
     */
    private function theatreCapacity(): array
    {
        $per_slot = $this->context->profile()->operationsPerSlot();
        $capacity = [];

        foreach ($this->slots_by_day as $day => $by_speciality) {
            foreach ($by_speciality as $speciality => $slots) {
                $capacity[$day][$speciality] = count($slots) * $per_slot;
            }
        }

        return $capacity;
    }

    /**
     * @throws SeedException
     */
    private function buildOne(SejourPlan $plan, CPatient $patient): CSejour
    {
        $random  = $this->context->stream('sejours');
        $service = $this->referential->service($plan->service());

        $practitioners = $this->referential->practitioners($plan->service());

        $sejour                = new CSejour();
        $sejour->patient_id    = $patient->_id;
        $sejour->praticien_id  = $random->pick($practitioners)->_id;
        $sejour->group_id      = $this->referential->group()->_id;
        $sejour->service_id    = $service->_id;
        $sejour->type          = $plan->type();
        $sejour->modalite      = 'libre';
        $sejour->chambre_seule = '0';
        $sejour->entree_prevue = $plan->entreePrevue();
        $sejour->sortie_prevue = $plan->sortiePrevue();
        $sejour->entree_reelle = $plan->entreeReelle();
        $sejour->sortie_reelle = $plan->sortieReelle();
        $sejour->facturable    = '1';
        $sejour->mode_entree   = $plan->modeEntree();
        $sejour->mode_sortie   = $plan->modeSortie();

        // L'INTERVENTION EST DÉCIDÉE AVANT QUE LE SÉJOUR NE SOIT ÉCRIT, et écrite après. Elle ne peut
        // pas être écrite avant — il lui faut l'identifiant du séjour — mais le diagnostic principal,
        // lui, doit être connu au moment du `store()` du séjour, et il découle de l'acte. Décider puis
        // écrire sépare proprement les deux, sans repasser sur un séjour déjà écrit.
        $planned = $plan->withOperation() ? $this->planOperation($plan, $patient) : null;

        $sejour->DP = $this->diagnosisFor($plan, $planned === null ? null : $planned['procedure']);

        // LE DIAGNOSTIC RELIÉ PORTE L'INCIDENT. Sans lui, le compte rendu pourrait raconter une
        // hémorragie que le séjour ne mentionne nulle part — un dossier qui se contredit apprend à
        // son lecteur qu'il ne faut pas s'y fier, ce qui est pire que pas d'incident du tout.
        $incident = $planned === null ? null : ($planned['incident'] ?? null);

        if ($incident !== null) {
            $code = SurgicalIncidents::cim10($incident);

            if ($code !== null) {
                $sejour->DR = $code;
            }
        }

        // Motif imposé par le fichier d'ancres, s'il en donne un, et UNE SEULE FOIS : un ancré
        // reversé dans la population générale peut obtenir d'autres séjours, et le motif écrit par
        // le scénario décrit un épisode, pas la vie entière du patient.
        $anchor_id = (int)$patient->_id;

        if (($this->anchor_clinical[$anchor_id]['motif_sejour'] ?? '') !== '') {
            $sejour->libelle = $this->anchor_clinical[$anchor_id]['motif_sejour'];

            $this->anchor_clinical[$anchor_id]['motif_sejour'] = '';
        }

        // Drapeau du socle porté par le SÉJOUR et non par l'unité : c'est lui que l'application
        // regarde pour distinguer une hospitalisation de courte durée d'un passage en box.
        if ($plan->service() === 'uhcd') {
            $sejour->UHCD = '1';
        }

        // `entree` et `sortie` sont `derived` : les renseigner serait à la fois inutile et faux,
        // le socle les calcule à partir des dates réelles et prévues.

        /** @var CSejour $sejour */
        $sejour = $this->context->write($sejour);

        if ($plan->createsAffectation()) {
            $admitting_unit = $this->buildAffectations($plan, $sejour);

            // LE DOSSIER DE SOINS, signé par le personnel de L'UNITÉ D'ADMISSION. Sans cet écrit,
            // un séjour ouvert à l'écran est vide — et le périmètre par unité, qui viendra
            // ensuite, serait invérifiable faute d'objet à filtrer.
            if ($admitting_unit !== null) {
                $this->writeCareRecord($sejour, $admitting_unit, $plan);
            }
        }

        if ($planned !== null) {
            $this->writeOperation($planned, $sejour, $plan);
        }

        // LES PIÈCES JOINTES DU SÉJOUR — courrier d'adressage, imagerie de ville. Celles de
        // l'intervention sont posées par `writeOperation()`, qui seul connaît son identifiant.
        $this->documents()->buildForStay($sejour, $plan->operationIsEmergency());

        // LES CONSTANTES EN DERNIER, et c'est l'ordre qui l'impose : elles suivent l'intervention —
        // pouls accéléré et fébricule dans les heures qui suivent —, donc il faut son instant et sa
        // classe ASA, que `writeOperation()` vient seulement de poser sur un objet écrit.
        $this->vitals()->build(
            $sejour,
            $plan,
            $this->ageOf($patient),
            $planned === null ? 0 : (int)$planned['operation']->ASA,
            $planned === null ? null : (string)$planned['operation']->debut_op
        );

        return $sejour;
    }

    /**
     * Le bâtisseur des constantes, construit à la demande.
     */
    private function vitals(): VitalsBuilder
    {
        if ($this->vitals === null) {
            $this->vitals = new VitalsBuilder($this->context);
        }

        return $this->vitals;
    }

    /**
     * Le bâtisseur des pièces jointes, construit à la demande.
     */
    private function documents(): DocumentBuilder
    {
        if ($this->documents === null) {
            $this->documents = new DocumentBuilder($this->context);
        }

        return $this->documents;
    }

    /**
     * Les pièces jointes écrites : courriers, consentements, imageries, anapaths.
     *
     * ⚠ LE COMPTEUR « refuses » EST CELUI QUI COMPTE. `CFile::store()` rend une CHAÎNE D'ERREUR
     * plutôt que de lever quand le répertoire `files/` n'est pas inscriptible — ce qui était le cas
     * de toute instance avant le 2026-10-07, le volume étant créé en root par l'entrypoint. Un
     * semis sur une instance mal réglée rapporterait donc des milliers de refus ici, là où sans ce
     * compteur il rapporterait un succès et un onglet de documents vide.
     *
     * @return array<string, int>
     */
    public function documentCounters(): array
    {
        return $this->documents === null
            ? [
                'fichiers'      => 0,
                'courriers'     => 0,
                'consentements' => 0,
                'imageries'     => 0,
                'anapaths'      => 0,
                'refuses'       => 0,
            ]
            : $this->documents->counters();
    }

    /**
     * Les constantes écrites : relevés et séjours couverts.
     *
     * @return array<string, int>
     */
    public function vitalsCounters(): array
    {
        return $this->vitals === null ? ['releves' => 0, 'sejours' => 0] : $this->vitals->counters();
    }

    /**
     * L'âge du patient à l'instant de référence du semis.
     *
     * CALCULÉ ET NON LU : `CPatient` porte `_age`, mais il n'est renseigné que par
     * `updateFormFields()`, donc absent sur un objet qu'on vient d'écrire. Le déduire de la date de
     * naissance évite de dépendre de l'ordre des appels du socle.
     */
    private function ageOf(CPatient $patient): int
    {
        if (!$patient->naissance) {
            return 40;
        }

        $years = CMbDT::daysRelative((string)$patient->naissance, $this->context->today());

        return $years === null ? 40 : (int)floor($years / 365.25);
    }

    /**
     * Affectations successives du séjour, contiguës et incluses dans ses bornes réelles.
     *
     * Un séjour planifié n'en reçoit aucune : il n'a pas de bornes réelles, et le lit n'est pas
     * attribué avant l'admission — c'est aussi ce que fait un service en vrai.
     *
     * @throws SeedException
     */
    private function buildAffectations(SejourPlan $plan, CSejour $sejour): ?string
    {
        $admitting = null;

        $bounds = $plan->stayBounds();
        if ($bounds === null) {
            return null;
        }

        [$from, $to] = $bounds;

        $segments = $this->splitStay($from, $to, $plan->segments());

        $services = $this->segmentServices($plan, count($segments));

        foreach ($segments as $index => $segment) {
            $slug = $services[$index];

            $bed = null;
            if ($plan->needsBed()) {
                $placement = $this->findBed($slug, $segment[0], $segment[1], $plan->isCurrent());

                // Le service de l'affectation suit le LIT réellement attribué : quand le séjour
                // débordait sur une autre unité, l'affectation annonçait le service d'origine tout
                // en occupant un lit d'ailleurs — c'est l'incohérence lit/service que l'invariant 1
                // interdit. Le séjour, lui, garde son service : c'est exactement un hébergement.
                if ($placement !== null) {
                    [$bed, $slug] = $placement;

                    if ($admitting === null) {
                        $admitting = $slug;
                    }
                }
            }

            $affectation             = new CAffectation();
            $affectation->sejour_id  = $sejour->_id;
            $affectation->service_id = $this->referential->service($slug)->_id;
            // Renseigné explicitement : CAffectation::store() le déduirait du séjour, mais le
            // chemin d'écriture par lots ne passe pas par store(). Le poser ici fait converger les
            // deux chemins sur la même valeur — celle que l'ORM aurait choisie.
            $affectation->praticien_id = $sejour->praticien_id;
            $affectation->lit_id     = $bed === null ? null : $bed;
            $affectation->entree     = $segment[0];
            $affectation->sortie     = $segment[1];
            $affectation->effectue   = $plan->isClosed() ? '1' : '0';

            $this->context->write($affectation);
        }

        return $admitting;
    }

    /**
     * Découpe le séjour en tranches CONTIGUËS : la sortie d'une affectation est l'entrée de la
     * suivante. C'est l'invariant 3, et une tranche vide le violerait par construction.
     *
     * @return array<int, string[]>
     * @throws SeedException
     */
    private function splitStay(string $from, string $to, int $segments): array
    {
        if ($segments <= 1) {
            return [[$from, $to]];
        }

        $minutes = (int)round((strtotime($to) - strtotime($from)) / 60);

        // Une mutation ne peut pas couper un séjour trop court : mieux vaut une seule affectation
        // qu'une tranche de quelques minutes.
        if ($minutes < 2 * 60 * 24) {
            return [[$from, $to]];
        }

        $cut_minutes = (int)floor($minutes / 2);
        $cut         = CMbDT::dateTime("+{$cut_minutes} minutes", $from);

        // Le point de coupe est ramené à une heure ronde : une mutation se note à l'heure.
        $cut = substr($cut, 0, 13) . ':00:00';

        if (strcmp($cut, $from) <= 0 || strcmp($cut, $to) >= 0) {
            return [[$from, $to]];
        }

        return [[$from, $cut], [$cut, $to]];
    }

    /**
     * Service de chaque tranche. Une mutation change de service, sinon rien ne la distinguerait
     * d'une affectation unique.
     *
     * @return string[]
     * @throws SeedException
     */
    private function segmentServices(SejourPlan $plan, int $count): array
    {
        $services = [$plan->service()];

        if ($count < 2) {
            return $services;
        }

        $random     = $this->context->stream('mutations');
        $candidates = [];
        $family     = $this->familyOf($plan->service());

        // Une mutation reste DANS LA MÊME FAMILLE d'unités. Le premier jet n'excluait que les soins
        // continus : le second segment d'un séjour d'hospitalisation pouvait atterrir dans un box
        // d'urgence, ce qui occupait un box au détriment du calibrage de l'unité — et n'a aucun sens
        // clinique, un patient ne mute pas vers les urgences.
        foreach ($this->referential->serviceSlugs() as $slug) {
            if ($slug !== $plan->service() && $this->familyOf($slug) === $family) {
                $candidates[] = $slug;
            }
        }

        $services[] = $candidates === [] ? $plan->service() : (string)$random->pick($candidates);

        return $services;
    }

    /**
     * Identifiant d'un lit libre pour la période, dans le service demandé de préférence.
     *
     * Un séjour EN COURS doit obtenir un lit : c'est lui qui porte l'occupation instantanée
     * visée, et échouer silencieusement donnerait un taux d'occupation faux. Un séjour clos, en
     * revanche, se rabat sur une affectation de service — un compteur le signale, pour que
     * l'opérateur sache que son profil est sur-souscrit.
     *
     * @return array{0: int, 1: string}|null lit et service qui le porte, ou null pour une
     *                                        affectation de service sans lit
     * @throws SeedException
     */
    private function findBed(string $slug, string $from, string $to, bool $mandatory): ?array
    {
        $random = $this->context->stream('lits');

        $preferred = $this->bedIds($slug);
        $bed       = $this->beds->firstFree($random->shuffle($preferred), $from, $to);
        $host      = $slug;

        if ($bed === null) {
            // Débordement sur les autres unités DE LA MÊME FAMILLE, ce que fait un établissement
            // plein avant de refuser une admission. Un séjour d'hospitalisation ne déborde pas dans
            // un box d'urgence, et un séjour urgent ne va pas en hospitalisation : le socle le
            // refuse pour le second, et le bon sens pour le premier.
            $family = $this->familyOf($slug);

            foreach ($random->shuffle($this->referential->serviceSlugs()) as $other) {
                if ($other === $slug || $this->familyOf($other) !== $family) {
                    continue;
                }

                $bed = $this->beds->firstFree($random->shuffle($this->bedIds($other)), $from, $to);
                if ($bed !== null) {
                    $host = $other;
                    break;
                }
            }
        }

        if ($bed === null) {
            if ($mandatory) {
                throw new SeedException(
                    "Aucun lit libre du {$from} au {$to} pour un séjour en cours."
                    . " L'occupation visée n'est pas atteignable avec cette capacité :"
                    . ' réduire --sejours ou augmenter --lits.'
                );
            }

            $this->bed_fallbacks++;

            return null;
        }

        $this->beds->allocate($bed, $from, $to);

        return [(int)$bed, $host];
    }

    /**
     * @return string[] identifiants de lits, en chaînes (clés de l'allocateur)
     * @throws SeedException
     */
    private function bedIds(string $slug): array
    {
        $ids = [];
        foreach ($this->referential->beds($slug) as $bed) {
            $ids[] = (string)$bed->_id;
        }

        return $ids;
    }

    /**
     * Intervention rattachée à une plage de SA salle, tenant dans la plage et ne chevauchant
     * aucune autre intervention de la même plage.
     *
     * Quand aucune plage n'est disponible le jour du séjour — week-end, ou séjour hors de la
     * période programmée — l'intervention n'est pas créée et un compteur le signale. Inventer une
     * plage ad hoc donnerait un programme opératoire incohérent avec le planning du bloc.
     *
     * L'intervention est DÉCIDÉE ici et écrite ailleurs : son acte détermine le diagnostic principal du
     * séjour, qui doit être posé avant que celui-ci ne soit écrit.
     *
     * @return array{operation: COperation, procedure: string}|null
     * @throws SeedException
     */
    private function planOperation(SejourPlan $plan, CPatient $patient): ?array
    {
        $random = $this->context->stream('operations');

        // Le jour vient du PLAN : le planificateur a calé le séjour sur un jour d'ouverture du
        // bloc. Les jours suivants du séjour servent de repli quand le programme de ce jour-là est
        // déjà complet — c'est ce que fait un bloc en vrai, il reporte au lendemain.
        $pool = (string)$plan->operationPool();

        $slots = [];
        foreach ($this->operationDays($plan) as $day) {
            // Les plages d'une même journée sont mélangées pour répartir le programme entre les
            // salles : les prendre dans l'ordre de création remplissait la salle 1 avant de
            // toucher la salle 2, ce qui se voit sur un tableau d'activité par salle.
            foreach ($random->shuffle($this->slots_by_day[$day][$pool] ?? []) as $slot) {
                $slots[] = $slot;
            }
        }

        if ($slots === []) {
            $this->unscheduled_operations++;

            return null;
        }

        [$min_minutes, $max_minutes] = $this->context->decor()->clinicalMix()->operationMinutes();

        $duration = self::OPERATION_STEP_MINUTES * (int)round(
            $random->int($min_minutes, $max_minutes) / self::OPERATION_STEP_MINUTES
        );

        // Les plages sont parcourues dans l'ordre : celles du jour prévu d'abord, puis le repli.
        foreach ($slots as $slot) {
            $placement = $this->placeInSlot(
                $slot,
                $duration,
                $plan->operationIsEmergency(),
                $random,
                $plan->effectiveBounds()
            );

            if ($placement === null) {
                continue;
            }

            [$start, $end] = $placement;

            $operation             = new COperation();
            $operation->chir_id    = $slot->chir_id;
            $operation->plageop_id = $slot->_id;
            $operation->salle_id   = $slot->salle_id;
            $operation->date       = $slot->date;
            $operation->debut_op   = $start;
            $operation->fin_op     = $end;
            $operation->cote       = (string)$random->pickWeighted(
                ['droit' => 30, 'gauche' => 30, 'non_applicable' => 25, 'bilatéral' => 10, 'total' => 5]
            );
            // L'acte suit la SPÉCIALITÉ DU CHIRURGIEN de la plage, et non le service du séjour :
            // pour une intervention programmée les deux coïncident, mais une intervention en
            // urgence est faite par le chirurgien de garde, dont la spécialité décide de l'acte.
            // Le sexe du patient écarte ensuite ce qui ne le concerne pas : une résection de
            // prostate sur une patiente est aussi visible qu'une colectomie en orthopédie.
            $speciality = (string)$this->referential->specialityOf((int)$slot->chir_id);

            $coding     = $this->context->decor()->coding();
            $candidates = $this->context->decor()->catalogue()->procedures(
                $speciality,
                (string)$patient->sexe
            );

            // LE VIVIER CCAM ÉLARGIT LA VARIÉTÉ, MAIS PAS POUR L'AMBULATOIRE, et la distinction est
            // clinique avant d'être technique.
            //
            // Un programme d'hospitalisation de JOUR est une liste SÉLECTIONNÉE : tout acte ne se
            // fait pas en ambulatoire, et le décor déclare précisément lesquels le peuvent. Tirer un
            // acte quelconque du vivier pour un séjour de jour détruirait cette contrainte — et
            // rendrait du même coup `fitStayType()` inopérant, puisque aucun libellé officiel ne
            // figure dans la liste ambulatoire, ce qui ferait basculer TOUS les séjours de jour dans
            // le repli « aucun acte ambulatoire » et fausserait le compteur qui le signale.
            //
            // Pour les autres séjours, rien ne s'oppose à la variété : le vivier rend les actes
            // thérapeutiques des paragraphes CCAM que la spécialité pratique déjà.
            $pool = [];

            if ($plan->type() !== self::DAY_CASE_TYPE) {
                $pool = CcamActPool::forSpeciality(
                    $speciality,
                    $this->specialitySeedCodes($speciality, $coding),
                    (string)$patient->sexe
                );
            }

            if ($pool !== []) {
                $code_from_pool     = (string)$random->pick(array_keys($pool));
                $procedure          = $pool[$code_from_pool];
                $operation->libelle = $procedure;
            } else {
                $code_from_pool     = null;
                $procedure          = (string)$random->pick($this->fitStayType($candidates, $plan, $coding));
                $operation->libelle = $procedure;
            }

            // COTATION CCAM. Le champ est une chaîne de codes séparés par des barres verticales, que
            // `CCodable` met en majuscules et dont il ne contrôle que la forme : aucun dictionnaire
            // n'est nécessaire pour l'écrire, contrairement au diagnostic du séjour. Un acte que le
            // décor ne code pas reste écrit sans cotation, et le rapport le compte.
            // LE CODE VIENT DU VIVIER QUAND C'EST LUI QUI A CHOISI : le libellé officiel n'est pas
            // dans la table du décor, et le chercher par libellé rendrait null — l'acte serait écrit
            // sans cotation alors que son code est précisément ce qu'on connaît le mieux.
            $code = $code_from_pool;

            if ($code === null) {
                $code = $coding === null ? null : $coding->ccamFor($procedure);
            }

            if ($code === null) {
                $this->uncoded_operations++;
            } else {
                $operation->codes_ccam = $code;
            }

            // L'anesthésiste vient de la VACATION : c'est lui qui tient la salle, et c'est lui qui
            // cotera l'activité 4. L'intervention ne le portait pas, alors que sa plage si.
            $operation->anesth_id = $slot->anesth_id ?: null;

            // L'INCIDENT EST DÉCIDÉ ICI, OÙ L'ACTE EST CONNU, et appliqué par l'appelant pour ce
            // qui touche le séjour — c'est le même partage que le diagnostic principal, déjà
            // documenté plus haut : « décider puis écrire sépare proprement les deux ».
            // LE SCORE ASA, posé AVANT le tirage parce que le risque anesthésique en dépend. Le
            // champ existait sur COperation et restait vide.
            $operation->ASA = (string)$random->pickWeighted($this->context->decor()->clinicalMix()->asaMix());

            // LA DURÉE PRÉVUE, SUR TOUTE INTERVENTION ET PAS SEULEMENT CELLES À INCIDENT. Le semis
            // ne remplissait que `debut_op`/`fin_op` — les horaires RÉALISÉS — en laissant
            // `temp_operation` à zéro sur les 347 interventions. Or c'est la durée prévue que le
            // planning du bloc utilise : sans elle, les écrans de programmation n'affichent aucune
            // durée. Constaté à l'écran par eriam.
            //
            // LES DEUX CHAMPS, et c'est nécessaire : COperation::updateFormFields() fait un
            // aller-retour entre `temp_operation` et `_time_op` (lignes 1024 et 1099), si bien que
            // poser l'un seul le fait écraser par l'autre selon l'ordre d'appel — et store() rend
            // un succès dans les deux cas.
            $planned_minutes = self::minutesBetween($start, $end);

            $incident = $this->drawIncident($random, $procedure, (int)$operation->ASA);

            if ($incident !== null) {
                // ON ALLONGE LA DURÉE ENREGISTRÉE, PAS LA PLAGE RÉSERVÉE. Repousser `fin_op`
                // chevaucherait l'intervention suivante dans la même salle, que le placement
                // ci-dessus a calée précisément pour l'éviter — et une salle où deux interventions
                // se superposent est une incohérence plus visible qu'une durée non répercutée.
                // `temp_operation` porte le temps opératoire constaté, et il était resté à zéro.
                //
                // LIMITE ASSUMÉE : dans un bloc réel, une conversion décale le programme de la
                // journée. Modéliser cette cascade demande de replanifier les interventions
                // suivantes, ce qui est un chantier à part — et c'est exactement le genre de
                // propagation où un jeu de données se contredit en silence.
                $planned_minutes = (int)round(
                    $planned_minutes * SurgicalIncidents::durationFactor($incident) / 1000
                );

                // LES DEUX CHAMPS, et c'est nécessaire — deux tentatives à un seul ont échoué avant
                // que je lise le mécanisme. `COperation::updateFormFields()` contient les DEUX
                // affectations : ligne 1024 « _time_op = temp_operation », ligne 1099
                // « temp_operation = _time_op ». C'est un aller-retour, donc poser un seul des deux
                // le fait écraser par l'autre selon l'ordre d'appel — et store() rend un succès
                // dans les deux cas. Mesuré : 346 interventions, zéro durée allongée, deux fois.
                // Les poser tous deux à la même valeur rend l'aller-retour inoffensif.
                $this->incidents[$incident] = ($this->incidents[$incident] ?? 0) + 1;
            }

            if ($planned_minutes > 0) {
                $temps = sprintf('%02d:%02d:00', intdiv($planned_minutes, 60), $planned_minutes % 60);

                $operation->temp_operation = $temps;
                $operation->_time_op       = $temps;
            }

            return ['sexe' => (string)$patient->sexe, 'operation' => $operation, 'procedure' => $procedure, 'incident' => $incident];
        }

        $this->unscheduled_operations++;

        return null;
    }

    /**
     * Tire un incident peropératoire, ou null.
     *
     * UN SEUL AU PLUS, et c'est un choix : cumuler une conversion, une hémorragie et un incident
     * anesthésique sur la même intervention est possible dans la vie réelle mais rare, et un semis
     * qui les cumulerait produirait des dossiers spectaculaires plutôt que plausibles. On parcourt
     * donc le catalogue dans son ORDRE DÉCLARÉ — qui fait partie de ce que la graine rejoue — et on
     * s'arrête au premier tiré.
     *
     * L'ordre n'est pas neutre : la conversion vient d'abord, parce qu'elle est la plus fréquente
     * et la moins grave. La placer après l'hémorragie donnerait des conversions plus rares que le
     * taux publié, le tirage s'arrêtant avant.
     */
    private function drawIncident(SeedRandom $random, string $procedure, int $asa): ?string
    {
        $mix = $this->context->decor()->clinicalMix();

        foreach (SurgicalIncidents::names() as $incident) {
            if (!SurgicalIncidents::appliesTo($incident, $procedure)) {
                continue;
            }

            $rate = $mix->incidentRate($incident);

            if ($incident === SurgicalIncidents::ANESTHESIE) {
                $rate *= $mix->incidentAnaesthesiaFactorFor($asa);
            }

            // En DIX-MILLIÈMES : les taux les plus bas sont sous 1 %, et les exprimer en pour mille
            // les arrondirait grossièrement — une plaie d'organe à 0,5 % deviendrait 0 ou 1 %.
            if ($rate > 0 && $random->int(1, 10000) <= $rate) {
                return $incident;
            }
        }

        return null;
    }

    /**
     * Minutes entre le début et la fin d'une intervention.
     *
     * CE QUE CETTE MÉTHODE A COÛTÉ, parce que l'erreur était instructive. Sa première version
     * concaténait « 1970-01-01 » devant ses arguments, en supposant des HEURES au format
     * « HH:MM:SS ». Or `debut_op` et `fin_op` sont des HORODATAGES COMPLETS —
     * « 2026-09-29 13:30:00 ». La concaténation produisait « 1970-01-01 2026-09-29 13:30:00 »,
     * `strtotime()` rendait false, la méthode rendait 0, et la durée s'écrivait « 00:00:00 ».
     *
     * J'en ai conclu trois fois de suite que le socle refusait l'écriture, et j'ai cherché la
     * cause dans COperation — l'aller-retour de `updateFormFields()`, un champ dérivé, un
     * deuxième `store()`. Vérification faite après coup : poser `temp_operation` ET `_time_op`
     * ensemble PERSISTE parfaitement. Le défaut était dans mon arithmétique, pas dans le leur.
     */
    private static function minutesBetween(string $start, string $end): int
    {
        $from = strtotime($start);
        $to   = strtotime($end);

        if ($from === false || $to === false || $to <= $from) {
            return 0;
        }

        return (int)round(($to - $from) / 60);
    }

    /**
     * Les incidents tirés, par type. Le rapport du semis les affiche.
     *
     * @return array<string, int>
     */
    public function incidents(): array
    {
        return $this->incidents;
    }

    /**
     * Restreint les actes à ceux que le TYPE DE SÉJOUR autorise.
     *
     * Un séjour de jour ne peut pas recevoir une gastrectomie. Le type est pourtant décidé par le mix
     * clinique avant que la spécialité de l'acte ne soit connue — l'acte suit le chirurgien de la plage
     * —, donc rien ne reliait les deux : mesuré sur un `small`, 26 interventions sur 47 tombaient sur un
     * séjour ambulatoire, dont des colectomies droites. Le filtre est de la même forme que celui du sexe,
     * et il se replie de la même façon : plutôt qu'une intervention sans libellé, la liste complète, mais
     * COMPTÉE.
     *
     * @param string[] $candidates
     *
     * @return string[]
     */
    private function fitStayType(array $candidates, SejourPlan $plan, ?ClinicalCoding $coding): array
    {
        if ($coding === null || $plan->type() !== self::DAY_CASE_TYPE) {
            return $candidates;
        }

        $eligible = [];
        foreach ($candidates as $procedure) {
            if ($coding->isAmbulatory($procedure)) {
                $eligible[] = $procedure;
            }
        }

        if ($eligible === []) {
            $this->non_ambulatory_operations++;

            return $candidates;
        }

        return $eligible;
    }

    /**
     * LE DOSSIER DE SOINS : observations médicales et transmissions, signées par l'unité.
     *
     * CE QUI MANQUAIT. Le semis produisait 1 556 séjours et ZÉRO écrit clinique — ni observation,
     * ni transmission, ni constante. Un dossier ouvert à l'écran était vide, et le périmètre par
     * unité d'exercice restait invérifiable faute d'objet à filtrer : on ne peut pas constater
     * qu'une infirmière de réanimation ne voit pas les transmissions de cardiologie s'il n'y en a
     * aucune.
     *
     * L'AUTEUR VIENT DE L'UNITÉ, et c'est le point entier. Une observation est signée par un
     * praticien du service, une transmission par un soignant du même service — c'est cette
     * signature qui rend le dossier crédible, et c'est elle que le filtre par périmètre
     * exploitera. Un écrit signé par n'importe qui n'aurait servi à rien.
     *
     * ⚠ LE TEXTE EST PROVISOIRE ET DÉLIBÉRÉMENT PAUVRE. Il ne contient AUCUN fait clinique qui ne
     * soit déjà dans la base : l'unité, la date, et l'incident quand le séjour en porte un. Pas de
     * « surveillance sans particularité », pas de « patient algique » — inventer un fait clinique
     * dans une prose de remplissage produirait un dossier qui se contredit, et c'est exactement ce
     * que le corpus de fragments à venir doit éviter. Quand il existera, il remplacera ces lignes.
     */
    private function writeCareRecord(CSejour $sejour, string $unit, SejourPlan $plan): void
    {
        $bounds = $plan->stayBounds();

        if ($bounds === null) {
            return;
        }

        [$from, $to] = $bounds;
        $random      = $this->context->stream('dossier-soins');
        $mix         = $this->context->decor()->clinicalMix();
        $service     = (string)$this->referential->service($unit)->nom;

        // Les praticiens de l'unité sont garantis par le référentiel ; les soignants peuvent
        // manquer sur une unité sans infirmier inscrit, et on retombe alors sur le praticien
        // plutôt que de ne rien écrire.
        $authors_obs   = $this->referential->practitioners($unit);
        $authors_trans = $this->referential->caregivers($unit, 'infirmier') ?: $authors_obs;

        $incident = (string)$sejour->DR !== ''
            ? sprintf(' Complication codée au séjour : %s.', (string)$sejour->DR)
            : '';

        for ($i = 1; $i <= $mix->observationsPerStay(); $i++) {
            $observation             = new CObservationMedicale();
            $observation->sejour_id  = $sejour->_id;
            $observation->user_id    = $random->pick($authors_obs)->_id;
            $observation->date       = $this->momentWithin($random, $from, $to);
            $observation->degre      = $random->chanceInThousand($mix->careRecordHighDegreeRate()) ? 'high' : 'low';
            $observation->type       = (string)$random->pick(['reevaluation', 'synthese', 'communication']);
            $observation->text       = sprintf('Suivi du séjour en %s.%s', $service, $incident);

            $this->context->write($observation);
            $this->care_records['observations']++;
        }

        for ($i = 1; $i <= $mix->transmissionsPerStay(); $i++) {
            $transmission             = new CTransmissionMedicale();
            $transmission->sejour_id  = $sejour->_id;
            $transmission->user_id    = $random->pick($authors_trans)->_id;
            $transmission->date       = $this->momentWithin($random, $from, $to);
            $transmission->degre      = $random->chanceInThousand($mix->careRecordHighDegreeRate()) ? 'high' : 'low';
            $transmission->text       = sprintf('Relève en %s.%s', $service, $incident);

            $this->context->write($transmission);
            $this->care_records['transmissions']++;
        }
    }

    /**
     * Un instant tiré DANS les bornes du séjour.
     *
     * Dans les bornes et non autour : une transmission datée avant l'admission ou après la sortie
     * est une incohérence qu'un écran de dossier montre tout de suite, en affichant un écrit qui
     * n'appartient à aucune période de présence.
     */
    private function momentWithin(SeedRandom $random, string $from, string $to): string
    {
        $start = strtotime($from);
        $end   = strtotime($to);

        if ($start === false || $end === false || $end <= $start) {
            return $from;
        }

        return date('Y-m-d H:i:s', $random->int($start, $end));
    }

    /**
     * Taille cumulée des viviers constitués pendant ce semis.
     *
     * CUMULÉE SUR LES SPÉCIALITÉS RENCONTRÉES, et non sur le décor entier : c'est ce que le semis a
     * réellement employé qui renseigne, pas ce qu'il aurait pu employer.
     */
    private function poolSize(): int
    {
        $coding = $this->context->decor()->coding();
        $total  = 0;

        foreach (array_keys($this->speciality_seed_codes) as $speciality) {
            $total += count(
                CcamActPool::forSpeciality($speciality, $this->specialitySeedCodes($speciality, $coding))
            );
        }

        return $total;
    }

    /**
     * Les codes CCAM que le décor déclare pour cette spécialité.
     *
     * ILS SERVENT DE GRAINE AU VIVIER : c'est d'eux que `CcamActPool` déduit les paragraphes de la
     * CCAM que la spécialité pratique. On prend les actes SANS filtrer sur le sexe du patient —
     * c'est le vivier qui applique ce filtre, à partir de la colonne du dictionnaire — car un décor
     * qui ne déclare qu'un acte féminin pour une spécialité ne doit pas priver les hommes de tout
     * son paragraphe.
     *
     * @return string[]
     */
    private function specialitySeedCodes(string $speciality, ?ClinicalCoding $coding): array
    {
        if ($coding === null) {
            return [];
        }

        if (isset($this->speciality_seed_codes[$speciality])) {
            return $this->speciality_seed_codes[$speciality];
        }

        $codes = [];

        foreach ($this->context->decor()->catalogue()->procedures($speciality) as $_procedure) {
            $code = $coding->ccamFor((string)$_procedure);

            if ($code !== null) {
                $codes[] = $code;
            }
        }

        return $this->speciality_seed_codes[$speciality] = $codes;
    }

    /**
     * Le bâtisseur des comptes rendus, construit à la demande.
     */
    private function reports(): CompteRenduBuilder
    {
        if ($this->reports === null) {
            $this->reports = new CompteRenduBuilder($this->context);
        }

        return $this->reports;
    }

    /**
     * Les comptes rendus écrits : opératoires, anesthésiques, et ce qu'ils couvrent.
     *
     * @return array<string, int>
     */
    public function reportCounters(): array
    {
        return $this->reports === null
            ? ['operatoires' => 0, 'anesthesiques' => 0, 'actes_generiques' => 0, 'incidents_narres' => 0]
            : $this->reports->counters();
    }

    /**
     * Le bâtisseur du parcours anesthésique, construit à la demande.
     *
     * PARESSEUX ET NON DANS LE CONSTRUCTEUR : il tient un cache de plages de consultation, et un
     * semis sans intervention programmée n'a aucune raison de l'instancier.
     */
    private function anaesthesiaBuilder(): AnaesthesiaBuilder
    {
        if ($this->anaesthesia === null) {
            $this->anaesthesia = new AnaesthesiaBuilder($this->context, $this->referential);
        }

        return $this->anaesthesia;
    }

    /**
     * Écrit l'intervention décidée par `planOperation()`, une fois le séjour écrit et son identifiant
     * connu.
     */
    private function writeOperation(array $planned, CSejour $sejour, SejourPlan $plan): void
    {
        /** @var COperation $operation */
        $operation            = $planned['operation'];
        $operation->sejour_id = $sejour->_id;

        // LE PARCOURS ANESTHÉSIQUE AVANT L'ÉCRITURE, et c'est ce qui dicte l'ordre ici. La visite
        // pré-anesthésique ne crée pas d'objet : elle se consigne SUR l'intervention
        // (date_visite_anesth, prat_visite_anesth_id). La poser après l'écriture obligerait à
        // réenregistrer l'intervention une seconde fois — deux écritures pour une ligne, et un
        // décompte faux dans le rapport du semis.
        //
        // La consultation, elle, a besoin du séjour, qui est déjà écrit à ce stade : son
        // identifiant est connu. Les deux contraintes se rencontrent donc exactement ici.
        $emergency = $plan->operationIsEmergency();

        // L'URGENCE EST ÉCRITE SUR L'INTERVENTION, et elle ne l'était pas. Le plan savait depuis
        // toujours laquelle est non programmée — le compteur du rapport s'en sert — mais le champ
        // `urgence` restait à sa valeur par défaut sur les 347 interventions. Le jeu de données
        // affirmait donc que rien n'avait été opéré en urgence, et aucun écran ne pouvait les
        // distinguer.
        //
        // ⚠ LA CHAÎNE '1', PAS L'ENTIER 1 : `urgence` est un enum('0','1'), et MySQL interprète un
        // entier comme un INDEX dans la liste — écrire 1 y poserait la première valeur, c'est-à-dire
        // « 0 ». Le même piège a faussé la mesure de contrôle de ce correctif, où SUM(urgence)
        // rendait 347 sur une colonne entièrement à '0'.
        $operation->urgence = $emergency ? '1' : '0';

        $dossier = $this->anaesthesiaBuilder()->build(
            $operation,
            (string)$sejour->patient_id,
            (string)($planned['sexe'] ?? ''),
            $emergency
        );

        $this->context->write($operation);

        // APRÈS l'intervention, parce que le dossier d'anesthésie la référence.
        if ($dossier !== null && $operation->_id) {
            $dossier->operation_id = $operation->_id;

            if ($this->context->write($dossier)->_id) {
                $this->anaesthesiaBuilder()->countDossier();
            }
        }

        // LES COMPTES RENDUS, APRÈS l'intervention : ils s'y accrochent par `object_id`, qui exige
        // son identifiant. L'incident vient du PLAN et non d'une relecture des données — c'est lui
        // qui a décidé de l'allongement de la durée et du diagnostic relié, et le récit doit dire
        // exactement ce que les faits portent.
        $this->reports()->build($operation, $planned['incident'] ?? null);

        // LES PIÈCES JOINTES DE L'INTERVENTION — consentement, anapath — après elle aussi, et pour
        // la même raison : `CFile::object_id` exige son identifiant.
        $this->documents()->buildForOperation($operation);

        $this->operations[$emergency ? 'urgence' : 'programme']++;

        $this->writeCcamActes($operation);
    }

    /**
     * Cote l'intervention : le geste du chirurgien, et l'anesthésie quand elle a lieu d'être.
     *
     * POURQUOI DEUX ACTES ET NON UN. En CCAM, un même code porte plusieurs ACTIVITÉS : la 1 est le
     * geste, la 4 l'anesthésie. Le chirurgien cote la première, l'anesthésiste la seconde, sur le même
     * code et la même intervention — c'est ainsi qu'un bloc facture, et `CCodageCCAM` en tient deux
     * feuilles distinctes puisque sa clé unique comprend le praticien et le drapeau d'anesthésie.
     *
     * L'ACTIVITÉ 4 N'EST PAS SYSTÉMATIQUE, et c'est le dictionnaire qui tranche, pas une règle écrite
     * ici : `QZFA036`, l'exérèse d'une lésion cutanée de moins de 5 cm², ne la déclare pas — elle se
     * fait sous anesthésie locale, sans anesthésiste. Coter une anesthésie dessus serait faux.
     *
     * Le reste est fait par le socle : `CActeCCAM::store()` crée le codage manquant, précode les
     * modificateurs applicables et calcule le montant de base. Une seule chose lui échappe,
     * `coding_datetime`, qu'il estampille à l'horloge réelle — comme il le ferait d'une saisie.
     *
     * @throws SeedException
     */
    private function writeCcamActes(COperation $operation): void
    {
        $code = (string)$operation->codes_ccam;

        if (!$this->ccam_available || $code === '') {
            return;
        }

        $this->writeActe($operation, $code, self::CCAM_ACTIVITY_PROCEDURE, (int)$operation->chir_id);
        $this->ccam_actes['geste']++;

        if (!$operation->anesth_id || !$this->allowsAnaesthesia($code)) {
            return;
        }

        $this->writeActe($operation, $code, self::CCAM_ACTIVITY_ANAESTHESIA, (int)$operation->anesth_id);
        $this->ccam_actes['anesthesie']++;
    }

    /**
     * @throws SeedException
     */
    private function writeActe(COperation $operation, string $code, int $activity, int $executant): void
    {
        // LA FEUILLE DE CODAGE EST ÉCRITE PAR LE SEEDER, ET C'EST DÉLIBÉRÉ. `CActeCCAM::check()` la
        // crée lui-même quand elle manque — mais elle échappe alors au traçage, donc à la purge :
        // `SeedPurger::assertClean()` ne compte que les objets passés par l'écrivain, et chaque run
        // aurait laissé derrière lui autant de lignes `codage_ccam` que d'actes. En la posant ici,
        // `check()` la retrouve (`loadMatchingObject`) au lieu d'en créer une.
        // Construite directement plutôt que par `CCodageCCAM::get()` : celui-ci commence par chercher
        // une feuille existante, or l'intervention vient d'être écrite et son couple
        // (praticien, activité) ne peut en avoir aucune. Une requête de moins par acte.
        //
        // Le socle pose ici `_apply_rules = false`, que sa visibilité protégée met hors de portée.
        // Sans conséquence : la règle d'association parcourt les actes DÉJÀ rattachés à la feuille,
        // et une feuille qui vient de naître n'en a aucun.
        $codage                  = new CCodageCCAM();
        $codage->codable_class   = 'COperation';
        $codage->codable_id      = $operation->_id;
        $codage->praticien_id    = $executant;
        $codage->activite_anesth = $activity === self::CCAM_ACTIVITY_ANAESTHESIA ? '1' : '0';
        $codage->date            = CMbDT::date(null, $operation->debut_op);

        $this->context->write($codage);

        $acte                = new CActeCCAM();
        $acte->object_class  = 'COperation';
        $acte->object_id     = $operation->_id;
        $acte->code_acte     = $code;
        $acte->code_activite = (string)$activity;
        // Phase 0 : la phase unique. Les actes en plusieurs temps sont rares et aucun du décor n'en a.
        $acte->code_phase   = '0';
        $acte->executant_id = $executant;
        // `debut_op` est un dateTime COMPLET, pas une heure — le concaténer à `date` donnait
        // « 2026-09-23 2026-09-23 13:30:00 ». La date d'exécution sert de date de référence au
        // dictionnaire : invalide, elle ne correspond à aucune date d'effet de modificateur, et le
        // codage échouait sans rien dire du vrai motif.
        $acte->execution  = $operation->debut_op;
        $acte->facturable = '1';

        $this->context->write($acte);
    }

    /**
     * L'acte admet-il l'activité d'anesthésie ? Le dictionnaire répond, une fois par code.
     *
     * @throws SeedException
     */
    private function allowsAnaesthesia(string $code): bool
    {
        if (!isset($this->anaesthesia_activity[$code])) {
            // LA PHASE 0 COMPTE AUTANT QUE L'ACTIVITÉ, et elle manquait à ce contrôle : huit actes
            // en vigueur déclarent l'activité d'anesthésie sans phase 0, et l'écriture échouerait
            // sur eux comme elle a échoué sur le geste. Voir CcamActPool::codesAnaesthesia().
            $this->anaesthesia_activity[$code] = CcamActPool::codesAnaesthesia($code);
        }

        return $this->anaesthesia_activity[$code];
    }

    /**
     * Diagnostic principal du séjour, en forme `abbrev` (sans point), ou chaîne vide.
     *
     * L'ORDRE DE PRÉFÉRENCE EST LE POINT ESSENTIEL : quand le séjour est opéré, le diagnostic est tiré
     * parmi ceux qui MOTIVENT l'acte, et seulement à défaut parmi ceux de l'unité. Tirer les deux
     * indépendamment donnerait une prothèse de hanche posée à un patient entré pour un canal carpien —
     * la même incohérence que celle corrigée au §12 sur les libellés, déplacée d'un cran.
     */
    private function diagnosisFor(SejourPlan $plan, ?string $procedure): string
    {
        $coding = $this->context->decor()->coding();

        // Sans dictionnaire monté, écrire un DP tuerait le processus : voir Cim10Dictionary.
        if ($coding === null || !$this->diagnoses_available) {
            return '';
        }

        $codes = $procedure === null ? [] : $coding->diagnosesForProcedure($procedure);

        if ($codes === []) {
            // DIAGNOSTIC DE LA SPÉCIALITÉ, FAUTE DE CORRESPONDANCE D'ACTE — et c'est devenu le cas
            // MAJORITAIRE depuis que les actes viennent du vivier CCAM : le décor ne tient la
            // correspondance acte -> diagnostic que pour ses soixante-dix actes écrits à la main.
            // La dégradation est gracieuse (le DP reste cohérent avec l'unité) mais elle est réelle,
            // et le rapport doit la dire plutôt que de laisser croire à un codage acte par acte.
            $this->speciality_diagnoses++;
            $codes = $coding->diagnosesFor($plan->service());
        }

        if ($codes === []) {
            $this->uncoded_sejours++;

            return '';
        }

        $this->coded_sejours++;

        // Flux dédié : ajouter ces tirages à celui des séjours aurait décalé tous les suivants, et
        // deux jeux produits avec la même graine n'auraient plus été identiques d'une version à
        // l'autre. Un flux nommé se rajoute sans rien déplacer.
        return (string)$this->context->stream('diagnostics')->pick($codes);
    }

    /**
     * Premier créneau libre de la plage pouvant accueillir la durée demandée.
     *
     * @return array{0: string, 1: string}|null
     * @throws SeedException
     */
    private function placeInSlot(
        CPlageOp $slot,
        int $duration,
        bool $anywhere = false,
        ?SeedRandom $random = null,
        array $bounds = []
    ): ?array {
        $slot_start = $slot->date . ' ' . $slot->debut;
        $slot_end   = $slot->date . ' ' . $slot->fin;
        $key        = 'plage:' . $slot->_id;

        // L'INTERVENTION DOIT TOMBER DANS LE SÉJOUR, et pas seulement le même jour. Le planificateur
        // cale le séjour sur un jour d'ouverture du bloc, mais rien ne reliait l'HEURE de la vacation
        // aux bornes du séjour : un patient admis à 17 h se retrouvait opéré à 13 h 30, le même jour.
        // C'est le socle qui l'a révélé — CActe::store() refuse un acte dont l'exécution sort de
        // l'intervalle [entree, sortie] du séjour — mais l'incohérence précédait le codage.
        //
        // Les bornes sont celles que le socle dérive : réelle si elle existe, prévue sinon. La
        // comparaison de chaînes suffit, les dates SQL étant ordonnées lexicographiquement.
        if ($bounds !== []) {
            if (strcmp($bounds[0], $slot_start) > 0) {
                $slot_start = $bounds[0];
            }

            if (strcmp($bounds[1], $slot_end) < 0) {
                $slot_end = $bounds[1];
            }

            if (strcmp($slot_start, $slot_end) >= 0) {
                return null;
            }
        }

        $cursor = $slot_start;

        // Une intervention en urgence ne commence pas au début de la garde : elle a lieu quand le
        // patient arrive. Le curseur part donc d'un point tiré dans la plage, sans quoi toutes les
        // interventions de nuit auraient commencé à minuit pile.
        if ($anywhere && $random !== null) {
            $room = (int)round((strtotime($slot_end) - strtotime($slot_start)) / 60) - $duration;

            if ($room > 0) {
                $steps  = intdiv($room, self::OPERATION_STEP_MINUTES);
                $offset = $random->int(0, $steps) * self::OPERATION_STEP_MINUTES;
                $cursor = CMbDT::dateTime("+{$offset} minutes", $slot_start);
            }
        }

        while (true) {
            $end = CMbDT::dateTime("+{$duration} minutes", $cursor);

            if (strcmp($end, $slot_end) > 0) {
                return null;
            }

            if ($this->theatre->isFree($key, $cursor, $end)) {
                $this->theatre->allocate($key, $cursor, $end);

                return [$cursor, $end];
            }

            $cursor = CMbDT::dateTime('+' . self::OPERATION_STEP_MINUTES . ' minutes', $cursor);

            if (strcmp($cursor, $slot_end) >= 0) {
                return null;
            }
        }
    }

    /**
     * Jours candidats pour l'intervention : le jour opératoire du plan, puis les jours suivants du
     * séjour, comme repli.
     *
     * @return string[]
     * @throws SeedException
     */
    private function operationDays(SejourPlan $plan): array
    {
        $target = $plan->operationDay();

        if ($target === null) {
            return [];
        }

        $days   = [$target];
        $bounds = $plan->stayBounds();
        $last   = substr($bounds === null ? $plan->sortiePrevue() : $bounds[1], 0, 10);

        $day = $target;
        while (strcmp($day, $last) < 0) {
            $day = CMbDT::date('+1 day', $day);

            if (isset($this->slots_by_day[$day][(string)$plan->operationPool()])) {
                $days[] = $day;
            }
        }

        return $days;
    }
}
