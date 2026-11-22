<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

use Ox\Cli\Seed\Decor\ClinicalMix;
use Ox\Cli\Seed\Decor\FrenchHospitalDecor;
use Ox\Cli\Seed\Decor\SeedDecor;

/**
 * Échelle d'un jeu de données de seed : des DONNÉES, et non des constantes dispersées dans
 * les builders, pour qu'on puisse lire l'ordre de grandeur d'un profil d'un seul coup d'œil
 * et le surcharger sans toucher au code.
 *
 * Les proportions cliniques (mix de types de séjour, répartition des états) sont ici pour la
 * même raison. Ce sont des valeurs PLAUSIBLES, pas des données sourcées : elles sont
 * paramétrables et devraient être validées par un professionnel du domaine.
 */
final class SeedProfile
{
    public const SMALL  = 'small';
    public const MEDIUM = 'medium';
    public const LARGE  = 'large';
    public const XL     = 'xl';

    public const DEFAULT_PROFILE = self::MEDIUM;

    /** Chemins d'écriture, cf. SeedWriter. */
    public const WRITER_ORM  = 'orm';
    public const WRITER_BULK = 'bulk';

    /**
     * Au-delà de ce nombre d'objets attendus, le chemin ORM devient trop lent (~5-20 ms par
     * objet, soit plus d'une minute) et le profil bascule sur l'écriture par lots.
     *
     * Le seuil porte sur le volume ESTIMÉ et non sur le nom du profil : un `--patients=50000` sur
     * `small` doit basculer aussi. Depuis que le nombre de séjours est déduit de la capacité, seul
     * `small` reste sous le seuil (~6 200 objets) ; `medium` en pèse 82 000.
     */
    private const BULK_THRESHOLD = 10000;













    /** Jours dans un mois, valeur moyenne : la fenêtre est exprimée en mois. */
    private const DAYS_PER_MONTH = 30.44;

    /**
     * @var array<string, array> Définition des profils livrés
     *
     * Trois grandeurs seulement, et c'est le point : les séjours et les patients ne sont PAS libres,
     * ils se déduisent de celles-ci (cf. requiredSejours()). Les laisser libres permettait d'écrire
     * un profil physiquement impossible — et les quatre profils livrés l'étaient tous, d'un facteur
     * 6 à 14.
     */
    private const DEFINITIONS = [
        self::SMALL  => ['services' => 6, 'beds' => 60, 'window' => 3],
        self::MEDIUM => ['services' => 12, 'beds' => 200, 'window' => 12],
        self::LARGE  => ['services' => 22, 'beds' => 600, 'window' => 12],
        self::XL     => ['services' => 42, 'beds' => 1200, 'window' => 24],
    ];



    /** @var string */
    private $name;

    /** @var int */
    private $services;

    /** @var int */
    private $beds;

    /** @var int|null Surcharge explicite de l'opérateur, sinon déduit */
    private $patients;

    /** @var int|null Surcharge explicite de l'opérateur, sinon déduit */
    private $sejours;

    /** @var int Fenêtre temporelle, en mois */
    private $window;

    /**
     * @var SeedDecor Le décor, qui porte tout ce qui n'est pas une échelle.
     *
     * Le profil en dépend et non l'inverse : « combien et à quelle densité » exige de connaître les
     * durées de séjour, les bandes d'occupation et la composition du catalogue. La dépendance va donc
     * dans ce sens, et il n'y a qu'une source de vérité — `SeedContext` expose le décor DU profil.
     */
    private $decor;

    private function __construct(
        string $name,
        int $services,
        int $beds,
        ?int $patients,
        ?int $sejours,
        int $window,
        SeedDecor $decor
    ) {
        $this->name     = $name;
        $this->services = $services;
        $this->beds     = $beds;
        $this->patients = $patients;
        $this->sejours  = $sejours;
        $this->window   = $window;
        $this->decor    = $decor;
    }

    public function decor(): SeedDecor
    {
        return $this->decor;
    }

    /**
     * Les proportions cliniques du décor. Raccourci : le profil les consulte à chaque calcul.
     *
     * @throws SeedException
     */
    private function mix(): ClinicalMix
    {
        return $this->decor->clinicalMix();
    }

    /**
     * @return string[]
     */
    public static function names(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    /**
     * @param array<string, int> $overrides services|beds|patients|sejours|window
     *
     * @throws SeedException
     */
    public static function named(
        string $name = self::DEFAULT_PROFILE,
        array $overrides = [],
        ?SeedDecor $decor = null
    ): self {
        if (!array_key_exists($name, self::DEFINITIONS)) {
            throw new SeedException(
                "Profil inconnu '{$name}' (attendu : " . implode(', ', self::names()) . ')'
            );
        }

        // Les séjours et les patients sont déduits, mais restent surchargeables : l'opérateur doit
        // pouvoir demander explicitement un jeu plus petit, quitte à sortir de la bande d'occupation.
        $values = self::DEFINITIONS[$name] + ['patients' => null, 'sejours' => null];

        foreach ($overrides as $key => $value) {
            if (!array_key_exists($key, $values)) {
                throw new SeedException(
                    "Surcharge inconnue '{$key}' (attendu : " . implode(', ', array_keys($values)) . ')'
                );
            }

            if (!is_int($value) || $value < 1) {
                throw new SeedException("Surcharge '{$key}' : entier positif attendu");
            }

            $values[$key] = $value;
        }

        return new self(
            $name,
            $values['services'],
            $values['beds'],
            $values['patients'],
            $values['sejours'],
            $values['window'],
            $decor ?? new FrenchHospitalDecor()
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    public function services(): int
    {
        return $this->services;
    }

    public function beds(): int
    {
        return $this->beds;
    }

    /**
     * Patients du jeu de données.
     *
     * Déduit des séjours : un patient qui compterait trente hospitalisations en un an ne ressemble
     * à rien, et c'est ce que donnait un nombre de patients fixe face à un nombre de séjours déduit
     * de la capacité.
     *
     * @throws SeedException
     */
    public function patients(): int
    {
        if ($this->patients !== null) {
            return $this->patients;
        }

        return max(1, (int)round(100 * $this->sejours() / $this->mix()->sejoursPerPatient()));
    }

    /**
     * @throws SeedException
     */
    public function sejours(): int
    {
        return $this->sejours ?? $this->requiredSejours();
    }

    /**
     * Nombre de séjours nécessaire pour soutenir l'occupation visée sur la fenêtre demandée.
     *
     * C'EST LA LOI DE LITTLE : l'occupation d'un lit est le produit d'un flux d'entrées par la durée
     * moyenne de séjour. Les trois grandeurs — lits, séjours, fenêtre — ne peuvent donc pas être
     * choisies indépendamment, et les quatre profils livrés l'avaient pourtant été : il aurait fallu
     * de 6 à 14 fois plus de séjours pour soutenir l'occupation annoncée. La conséquence se voyait
     * dans l'application — le mois courant portait une activité plusieurs fois supérieure à la
     * moyenne de l'historique, parce que les séjours en cours sont calibrés sur la capacité alors que
     * l'historique est étalé sur toute la fenêtre.
     *
     * Le calcul ne peut pas s'appliquer aux séjours toutes catégories confondues : seuls ceux qui
     * occupent un lit nommé entrent dans l'occupation. On déduit donc le nombre de séjours
     * d'hospitalisation complète, puis on remonte au total par la part que le mix leur accorde.
     *
     * @throws SeedException
     */
    public function requiredSejours(): int
    {
        return $this->scale()['sejours'];
    }

    /**
     * Lits réservés à l'accueil des urgences, boxes et UHCD confondus.
     *
     * @throws SeedException
     */
    public function emergencyBeds(): int
    {
        // Calculé sur le nombre de séjours EFFECTIF et non sur le nombre déduit : quand l'opérateur
        // impose `--sejours`, les boxes doivent suivre son activité réelle, sinon un petit jeu de
        // données se retrouverait avec les boxes d'un grand.
        $beds = (int)round($this->sejours() * $this->scale()['per_sejour']);

        return max(0, min($this->beds - 1, $beds));
    }

    /**
     * Séjours nécessaires et lits d'urgence, résolus ENSEMBLE.
     *
     * C'est la loi de Little appliquée aux TROIS familles de lits, et elles ne se résolvent pas
     * séparément. Le nombre de séjours dépend des lits d'hospitalisation, qui sont ce qui reste
     * après les lits d'urgence ; et le nombre de lits d'urgence dépend du flux de séjours urgents,
     * donc du nombre de séjours. Le système est linéaire, et il se résout d'un trait :
     *
     *     T = K · (B − e)      (les séjours que portent les lits d'hospitalisation et de soins continus)
     *     e = c · T            (les lits d'urgence que réclame le flux urgent)
     *     ⟹ T = K·B / (1 + K·c)
     *
     * Le résoudre par approximations successives, ou pire fixer les lits d'urgence à une part
     * arbitraire de la capacité, laissait l'une des trois familles hors de sa bande : avec une
     * répartition égale entre services, l'unité d'urgences d'un profil medium aurait reçu 34 lits
     * pour un flux qui en occupe 10.
     *
     * @return array{sejours: int, per_sejour: float}
     * @throws SeedException
     */
    private function scale(): array
    {
        $composition = $this->decor->catalogue()->counts($this->services);
        $days        = $this->window * self::DAYS_PER_MONTH;

        // Part du mix qui occupe un lit d'hospitalisation, et part qui occupe un lit d'urgence :
        // cf. SejourPlanner::BED_TYPES.
        $comp_share = ($this->mix()->typeMix()['comp'] ?? 0) / 100;
        $urg_share  = ($this->mix()->typeMix()['urg'] ?? 0) / 100;

        if ($comp_share <= 0) {
            throw new SeedException(
                "Le mix de types ne comporte aucun séjour d'hospitalisation complète : aucun séjour"
                . " ne peut occuper de lit, et l'occupation visée est inatteignable."
            );
        }

        // Les lits qui ne sont pas d'urgence se partagent à parts égales entre les autres unités.
        $hospitalisation_units = max(1, $composition['ward'] + $composition['intensive']);

        $per_bed = (
                $this->bedFlow(1, $days, $this->mix()->occupancyBand(), $this->mix()->duration('comp'))
                * $composition['ward'] / $hospitalisation_units
                + $this->bedFlow(1, $days, $this->mix()->icuOccupancyBand(), $this->mix()->icuDuration())
                * $composition['intensive'] / $hospitalisation_units
            ) / $comp_share;

        // Sans unité d'accueil, les séjours urgents ne sont pas hébergés et la capacité entière va
        // à l'hospitalisation.
        $per_sejour = 0.0;
        if ($composition['emergency'] > 0 && $urg_share > 0) {
            $emergency_flow_per_bed = $this->bedFlow(
                1,
                $days,
                $this->mix()->emergencyOccupancyBand(),
                $this->mix()->duration('urg')
            );

            $per_sejour = $emergency_flow_per_bed > 0 ? $urg_share / $emergency_flow_per_bed : 0.0;
        }

        $sejours = $per_bed * $this->beds / (1 + $per_bed * $per_sejour);

        return [
            'sejours'    => max(1, (int)round($sejours)),
            'per_sejour' => $per_sejour,
        ];
    }

    /**
     * Lits de chaque unité, par slug de spécialité.
     *
     * Les unités d'accueil des urgences reçoivent ce que leur flux exige ; le reste se partage à
     * parts égales. Une répartition uniforme sur toutes les unités donnait à l'unité d'urgences
     * trois fois les lits que son activité occupe.
     *
     * @param array<int, array{slug: string}> $specialities
     *
     * @return array<string, int> slug => lits
     * @throws SeedException
     */
    public function bedsPerService(array $specialities): array
    {
        $emergency = [];
        $others    = [];

        foreach ($specialities as $spec) {
            if ($this->decor->catalogue()->isEmergency($spec['slug'])) {
                $emergency[] = $spec['slug'];
            } else {
                $others[] = $spec['slug'];
            }
        }

        $beds = [];

        // Cas dégénéré, mais légal par surcharge : toutes les unités accueillent les urgences, ou
        // aucune. Elles se partagent alors la capacité entière, plutôt que de laisser
        // `distribute()` recevoir zéro part.
        if ($emergency === [] || $others === []) {
            $all = $emergency === [] ? $others : $emergency;

            foreach (self::distribute($this->beds, count($all)) as $i => $share) {
                $beds[$all[$i]] = $share;
            }

            return $beds;
        }

        // Les unités d'accueil prennent ce que leur flux exige, en laissant au moins un lit à chacune
        // des autres.
        $emergency_beds = min($this->beds - count($others), $this->emergencyBeds());
        $emergency_beds = max(count($emergency), $emergency_beds);

        foreach (self::distribute($emergency_beds, count($emergency)) as $i => $share) {
            $beds[$emergency[$i]] = $share;
        }

        foreach (self::distribute($this->beds - $emergency_beds, count($others)) as $i => $share) {
            $beds[$others[$i]] = $share;
        }

        return $beds;
    }

    /**
     * Entrées nécessaires sur la période pour tenir le milieu d'une bande d'occupation.
     *
     * @param int[] $band     [min, max] en pourcentage
     * @param int[] $duration [min, max] en jours
     */
    private function bedFlow(int $beds, float $days, array $band, array $duration): float
    {
        $mean_stay = ($duration[0] + $duration[1]) / 2;

        if ($beds <= 0 || $mean_stay <= 0) {
            return 0.0;
        }

        // BAS de la bande, et non son milieu. Le nombre de séjours fixe la densité de l'HISTORIQUE,
        // que l'allocateur de lits doit ensuite caser sans jamais revenir sur ses choix : à 80 % de
        // remplissage moyen, un placement glouton laisse un séjour sur neuf sans lit nommé, parce
        // qu'un système d'attente à ce taux bloque statistiquement. Viser le bas de la bande ramène
        // ce reste à quelques pour cent. L'occupation INSTANTANÉE, elle, reste calée sur le milieu de
        // la bande : c'est le nombre de séjours en cours qui la fixe, et lui n'a pas ce problème
        // puisqu'il est placé en premier.
        $occupancy = $band[0] / 100;

        return $beds * $occupancy * $days / $mean_stay;
    }

    /**
     * @return int[] [min, max] en jours
     */
    public function sejourDuration(string $type): array
    {
        return $this->mix()->duration($type);
    }

    /**
     * @return int[] [min, max] en jours, pour un séjour en soins continus
     */
    public function icuSejourDuration(): array
    {
        return $this->mix()->icuDuration();
    }

    /** Fenêtre temporelle du jeu de données, en mois. */
    public function windowMonths(): int
    {
        return $this->window;
    }

    /**
     * Profondeur de l'horizon FUTUR, en mois : les admissions à venir se planifient à quelques
     * semaines, pas sur la profondeur de l'historique.
     *
     * Cette valeur ne sert pas qu'aux séjours planifiés : le bloc doit ouvrir des plages sur le
     * même horizon, sinon les séjours chirurgicaux programmés au-delà n'ont aucune plage où
     * s'inscrire — et leur intervention était purement et simplement perdue.
     */
    public function futureHorizonMonths(): int
    {
        return max(1, (int)floor($this->window / $this->mix()->futureWindowDivisor()));
    }

    /**
     * Nombre de chambres nécessaires pour porter les lits du profil.
     *
     * Règle assumée : un tiers des chambres sont doubles, le reste individuelles, ce qui
     * donne un établissement crédible sans avoir à paramétrer une distribution. Les chambres
     * doubles sont ce qui rend `CSejour::chambre_seule` et les conflits de placement
     * intéressants pédagogiquement ; n'avoir que des chambres individuelles supprimerait le
     * sujet.
     *
     * @throws SeedException
     */
    public function rooms(): int
    {
        $double_rooms = intdiv($this->beds, $this->mix()->doubleRoomDivisor());
        $single_rooms = $this->beds - (2 * $double_rooms);

        return $double_rooms + $single_rooms;
    }

    public function doubleRooms(): int
    {
        return intdiv($this->beds, $this->mix()->doubleRoomDivisor());
    }

    /**
     * Ordre de grandeur du nombre total d'objets, servant à choisir le chemin d'écriture.
     *
     * Approximation volontairement grossière : référentiel + patients + séjours + une
     * affectation et un peu moins d'une opération par séjour.
     *
     * @throws SeedException
     */
    public function estimatedObjects(): int
    {
        $reference_data = $this->services + $this->rooms() + $this->beds;

        return $reference_data + $this->patients() + (3 * $this->sejours());
    }

    /**
     * Chemin d'écriture retenu quand l'opérateur laisse `--writer=auto`.
     *
     * @throws SeedException
     */
    public function defaultWriter(): string
    {
        return $this->estimatedObjects() > self::BULK_THRESHOLD ? self::WRITER_BULK : self::WRITER_ORM;
    }

    /**
     * Les entités de masse sont-elles tracées une à une dans CFixturesReference ?
     *
     * La politique de traçabilité suit LE CHEMIN D'ÉCRITURE, pas la classe d'objet : c'est ce
     * qui garde les deux modes cohérents. En ORM tout est tracé et
     * `ox-fixtures:load --only-purge` nettoie nominalement ; en bulk la purge se fait par
     * plages d'identifiants.
     *
     * @throws SeedException
     */
    public function tracesMassEntities(): bool
    {
        return $this->defaultWriter() === self::WRITER_ORM;
    }

    /**
     * @return array<string, int> type de séjour => pourcentage
     */
    public function sejourTypeMix(): array
    {
        return $this->mix()->typeMix();
    }

    /**
     * @return array<string, int> état => pourcentage
     */
    public function sejourStateMix(): array
    {
        return $this->mix()->stateMix();
    }

    /**
     * @return int[] [min, max] en pourcentage
     */
    public function occupancyBand(): array
    {
        return $this->mix()->occupancyBand();
    }

    /**
     * @return int[] [min, max] en pourcentage, pour les unités de soins continus
     */
    public function icuOccupancyBand(): array
    {
        return $this->mix()->icuOccupancyBand();
    }

    /**
     * @return int[] [min, max] en pourcentage, pour les unités d'accueil des urgences
     */
    public function emergencyOccupancyBand(): array
    {
        return $this->mix()->emergencyOccupancyBand();
    }

    /**
     * Interventions par plage : à la fois la moyenne servant à dimensionner le bloc et le plafond
     * que le planificateur applique jour par jour.
     *
     * Ce nombre tient TOUJOURS dans une vacation, parce que la durée maximale d'une intervention est
     * bornée à la moitié de celle-ci (cf. SejourBuilder::OPERATION_MAX_MINUTES). C'est un plafond
     * prudent — une vacation peut parfois prendre trois interventions courtes — et c'est voulu : une
     * intervention refusée à la planification est reportée à un autre jour, alors qu'une
     * intervention refusée à l'écriture est perdue.
     */
    public function operationsPerSlot(): int
    {
        return $this->mix()->operationsPerSlot();
    }

    /**
     * Nombre d'interventions attendues sur la fenêtre, déduit du mix de séjours.
     *
     * Ce calcul est le pivot du dimensionnement du bloc. Le premier jet déduisait le nombre de
     * salles du nombre de LITS : un établissement de 200 lits recevait trois salles, pour une
     * activité qui n'en remplissait pas une. Résultat, un planning opératoire vide à 83 %, ce qu'on
     * voit au premier écran. Les deux grandeurs n'ont pas de raison d'être proportionnelles ; ce
     * qui remplit un bloc, c'est le nombre d'actes.
     *
     * @param int $surgical_services Services chirurgicaux
     * @param int $ward_services     Services d'hospitalisation (hors soins continus)
     *
     * @throws SeedException
     */
    public function expectedOperations(int $surgical_services, int $ward_services): int
    {
        if ($ward_services < 1 || $surgical_services < 1) {
            return 0;
        }

        $mix = $this->sejourTypeMix();

        // Seuls les séjours complets et ambulatoires peuvent porter une intervention.
        $operable_share = (($mix['comp'] ?? 0) + ($mix['ambu'] ?? 0)) / 100;

        // Un séjour n'est opéré que s'il relève d'un service chirurgical.
        $surgical_share = $surgical_services / $ward_services;

        return (int)round($this->sejours() * $operable_share * $surgical_share * $this->mix()->operationRate() / 100);
    }

    /**
     * Salles et vacations quotidiennes du bloc, dimensionnées pour que le planning soit REMPLI.
     *
     * Le raisonnement est le même que pour les lits : on part de la charge, pas d'une proportion
     * arbitraire. La règle tient en une phrase — LE PLUS PETIT BLOC dont le programme reste sous le
     * plafond d'occupation — et l'ordre du parcours suffit à l'exprimer : salles croissantes, et pour
     * chacune, une vacation avant deux.
     *
     * Ce dernier point n'est pas cosmétique. Chercher la combinaison la plus PROCHE d'une cible
     * donnait quinze salles ouvertes le matin seulement pour `medium`, alors qu'un bloc ouvre une
     * deuxième vacation avant d'ouvrir une salle de plus : huit salles matin et après-midi offrent la
     * même capacité et ressemblent à un vrai établissement.
     *
     * @return array{rooms: int, sessions: int}
     * @throws SeedException
     */
    public function operatingTheatre(int $surgical_services, int $ward_services): array
    {
        $operations = $this->expectedOperations($surgical_services, $ward_services);

        if ($operations === 0) {
            return ['rooms' => 1, 'sessions' => 1];
        }

        // Jours ouvrés de la FENÊTRE PASSÉE seulement, alors que le bloc ouvre aussi sur l'horizon
        // programmé. Ce n'est pas une inattention : 90 % des séjours sont clos, donc 90 % des
        // interventions tombent dans cette fenêtre, et c'est là que la capacité doit répondre à la
        // demande. Prendre toute l'étendue des plages au dénominateur diluait la demande sur une
        // période où elle n'est pas — le bloc paraissait rempli à 73 % en moyenne alors qu'il
        // saturait à plus de 80 % sur l'historique, et 16 % des interventions ne trouvaient plus de
        // créneau. Les plages de l'horizon programmé restent donc peu occupées, ce qui est d'ailleurs
        // le cas d'un vrai programme opératoire : il se remplit à mesure que la date approche.
        $weekdays = max(1, (int)round($this->window * self::DAYS_PER_MONTH * 5 / 7));

        // Plages utilisées attendues : chaque plage occupée accueille en moyenne
        // OPERATIONS_PER_SLOT actes.
        $used_slots = $operations / $this->mix()->operationsPerSlot();

        for ($rooms = 1; $rooms <= $this->mix()->maxOperatingRooms(); $rooms++) {
            for ($sessions = 1; $sessions <= $this->mix()->sessionsPerDay(); $sessions++) {
                $usage = 100 * $used_slots / ($rooms * $sessions * $weekdays);

                if ($usage <= $this->mix()->targetSlotUsage()) {
                    return ['rooms' => $rooms, 'sessions' => $sessions];
                }
            }
        }

        // Aucune combinaison ne tient sous le plafond : on prend la plus grande capacité offerte, et
        // les interventions qui ne trouveront pas de place seront comptées et rapportées.
        return ['rooms' => $this->mix()->maxOperatingRooms(), 'sessions' => $this->mix()->sessionsPerDay()];
    }

    /**
     * Répartit $total unités sur $buckets parts, au plus juste et de façon déterministe : les
     * $total % $buckets premières parts reçoivent une unité de plus.
     *
     * Utilisé pour distribuer lits et séjours sur les services. Un tirage aléatoire ferait
     * l'affaire mais rendrait les comptes du profil approximatifs, alors que l'opérateur a
     * demandé un nombre précis de lits.
     *
     * @return int[]
     * @throws SeedException
     */
    public static function distribute(int $total, int $buckets): array
    {
        if ($buckets < 1) {
            throw new SeedException("Nombre de parts invalide : {$buckets}");
        }

        if ($total < 0) {
            throw new SeedException("Total négatif : {$total}");
        }

        $base      = intdiv($total, $buckets);
        $remainder = $total % $buckets;

        $shares = [];
        for ($i = 0; $i < $buckets; $i++) {
            $shares[] = $i < $remainder ? $base + 1 : $base;
        }

        return $shares;
    }

    /**
     * Résumé destiné à la sortie de la commande : ce qui a été demandé, pour que le jeu de
     * données soit rejouable à la lecture du journal.
     *
     * @return array<string, int|string>
     * @throws SeedException
     */
    public function describe(): array
    {
        return [
            'profil'    => $this->name,
            'services'  => $this->services,
            'lits'      => $this->beds,
            'chambres'  => $this->rooms(),
            'patients'  => $this->patients(),
            'sejours'   => $this->sejours(),
            'fenetre'   => $this->window . ' mois',
            'objets'    => $this->estimatedObjects(),
            'ecriture'  => $this->defaultWriter(),
        ];
    }
}
