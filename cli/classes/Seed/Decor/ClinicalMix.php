<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed\Decor;

use Ox\Cli\Seed\SeedException;

/**
 * Les proportions cliniques d'un décor : ce qui distingue un CHU d'une clinique de jour.
 *
 * POURQUOI UN OBJET DE VALEUR ET NON UNE INTERFACE : un décor n'a pas à réécrire vingt-cinq méthodes,
 * il a à dire ce qui DIFFÈRE. Les valeurs par défaut décrivent un centre hospitalier généraliste, et un
 * décor n'en surcharge que quelques-unes. Une clé inconnue est refusée, parce qu'une faute de frappe
 * dans un nom de paramètre produirait silencieusement le comportement par défaut.
 *
 * CES VALEURS SONT PLAUSIBLES, PAS SOURCÉES. Elles devraient être validées par un professionnel du
 * domaine. Ce qui est en revanche garanti, c'est qu'elles ne peuvent pas être INCOHÉRENTES entre elles :
 * le nombre de séjours se déduit des lits, de la fenêtre, des durées et des bandes d'occupation par la
 * loi de Little (cf. SeedProfile). Un décor qui doublerait les durées de séjour verrait donc le nombre
 * de séjours se diviser, sans avoir à le calculer.
 */
final class ClinicalMix
{
    /**
     * @var array<string, mixed>
     *
     * Chaque entrée est commentée là où sa valeur ne se devine pas. L'ordre suit le parcours du
     * patient : ce qu'il est, pourquoi il vient, combien de temps il reste, qui s'occupe de lui.
     */
    private const DEFAULTS = [
        // --- La population -------------------------------------------------------------------
        /** Âge de la majorité : au-dessous, un patient relève de la pédiatrie. */
        'adult_age'     => 18,
        'max_age'       => 95,
        'child_min_age' => 1,
        /** Part de femmes, en pour mille. */
        'female_rate' => 520,
        /** Part de femmes portant un nom d'usage différent du nom de naissance, en pour mille. */
        'married_name_rate' => 350,
        /** Séjours par patient, en moyenne, multiplié par cent. */
        'sejours_per_patient' => 250,

        // --- Le mix de séjours ---------------------------------------------------------------
        /** Types de séjour, en pourcentage. Les parts doivent totaliser cent. */
        'type_mix' => [
            'ambu'    => 40,
            'comp'    => 35,
            'urg'     => 10,
            'exte'    => 8,
            'seances' => 4,
            'consult' => 3,
        ],
        /**
         * Répartition des états, en pourcentage. « en cours » est une CIBLE INDICATIVE : le nombre de
         * séjours en cours est calibré sur la capacité en lits, pour que l'occupation instantanée
         * tombe dans les bandes visées.
         */
        'state_mix' => [
            'closed'  => 85,
            'planned' => 10,
            'current' => 5,
        ],
        /** Modes d'entrée possibles, pondérés : 8 = domicile, très majoritaire. */
        'mode_entree' => ['8' => 70, '7' => 12, '6' => 10, '0' => 5, 'N' => 3],
        /** Modes de sortie, pondérés. Le décès reste rare mais doit exister. */
        'mode_sortie' => [
            'normal'         => 88,
            'mutation'       => 5,
            'transfert'      => 4,
            'transfert_acte' => 2,
            'deces'          => 1,
        ],

        // --- Les durées ----------------------------------------------------------------------
        /** Durées de séjour en jours, par type : [min, max]. 0 = même jour. */
        'durations' => [
            'comp'    => [2, 12],
            'urg'     => [0, 2],
            'ambu'    => [0, 0],
            'exte'    => [0, 0],
            'seances' => [0, 0],
            'consult' => [0, 0],
            'ssr'     => [10, 45],
            'psy'     => [5, 30],
        ],
        /** Durée d'un séjour en soins continus : plus court qu'en médecine, et suivi d'une mutation. */
        'icu_duration' => [2, 9],
        /** Probabilité qu'un séjour complet comporte une mutation de service, en pour mille. */
        'mutation_rate' => 180,
        /** Heures d'arrivée : [min, max]. Les urgences n'attendent pas les heures ouvrables. */
        'arrival_hours'           => [7, 20],
        'emergency_arrival_hours' => [0, 23],
        // UN PATIENT OPÉRÉ EST ADMIS AVANT L'OUVERTURE DU BLOC, et s'il est opéré en ambulatoire il
        // sort le soir. Sans ces deux bandes, l'heure d'admission était tirée comme celle de
        // n'importe quel séjour — jusqu'à 20 h — alors que les vacations programmées se tiennent
        // entre 8 h et 18 h 30 : l'intervention tombait hors des bornes du séjour, et le socle la
        // refusait au codage. Mesuré sur un profil `medium` : 804 interventions sur 5 284 perdues.
        'surgical_arrival_hours'   => [6, 8],
        'surgical_discharge_hours' => [19, 21],

        // --- Les bandes d'occupation ---------------------------------------------------------
        /**
         * Trois familles de lits, trois bandes. Les mesurer ensemble donnerait un taux qui ne
         * correspondrait à aucune des trois.
         */
        'occupancy_band'           => [75, 85],
        'icu_occupancy_band'       => [55, 75],
        'emergency_occupancy_band' => [50, 70],
        /** Une chambre sur N est double : c'est ce qui rend les conflits de placement intéressants. */
        'double_room_divisor' => 3,
        /** Diviseur de la fenêtre donnant l'horizon des admissions programmées. */
        'future_window_divisor' => 4,

        // --- Le bloc opératoire --------------------------------------------------------------
        /** Proportion de séjours opérables effectivement opérés, en pourcentage. */
        'operation_rate' => 85,
        /** Probabilité qu'un séjour d'un service chirurgical soit opéré, en pour mille. */
        'surgical_operation_rate' => 850,
        /** Probabilité qu'un séjour urgent soit opéré, en pour mille. */
        'emergency_operation_rate' => 150,
        /** Interventions par plage : à la fois moyenne de dimensionnement et plafond par jour. */
        'operations_per_slot' => 2,
        /** PLAFOND d'occupation des plages, et non cible : sous-dimensionner fait perdre des actes. */
        'target_slot_usage' => 75,
        'sessions_per_day'  => 2,
        /** Garde-fou de vraisemblance : au-delà, aucun établissement réel n'a autant de salles. */
        'max_operating_rooms' => 50,
        /** Décalage maximal d'un séjour pour tomber sur un jour opératoire, en jours. */
        'max_operating_shift' => 7,
        /** Durée d'une intervention, en minutes. Le maximum vaut la moitié d'une vacation. */
        'operation_minutes' => [45, 150],
        /** Vacations du bloc programmé. Toutes de même durée : la capacité en dépend. */
        'theatre_sessions' => [
            ['debut' => '08:00:00', 'fin' => '13:00:00'],
            ['debut' => '13:30:00', 'fin' => '18:30:00'],
        ],
        /** Vacations du bloc d'urgence : la journée entière, découpée en gardes. */
        'emergency_sessions' => [
            ['debut' => '00:00:00', 'fin' => '08:00:00'],
            ['debut' => '08:00:00', 'fin' => '16:00:00'],
            ['debut' => '16:00:00', 'fin' => '23:59:00'],
        ],

        // --- Le personnel --------------------------------------------------------------------
        /** Praticiens par service, pour que les séjours ne soient pas tous au même nom. */
        'practitioners_per_service' => 3,
        /** Praticiens de garde en plus, par spécialité chirurgicale. */
        'surgeons_on_call' => 1,
        /** Une salle ouverte occupe un anesthésiste, plus une marge pour les absences et la garde. */
        'anaesthetists_per_room' => 1,
        'anaesthesia_spare'      => 2,

        // --- Le personnel soignant, EN MILLIÈMES DE LIT -------------------------------------
        //
        // POURQUOI DES RATIOS PAR LIT ET NON UN NOMBRE FIXE : un effectif soignant se dimensionne
        // sur la capacité et sur la nature de l'unité, pas sur le nombre de services. Un service
        // de douze lits et une réanimation de huit n'emploient pas le même monde.
        //
        // LES VALEURS SONT DES EFFECTIFS INSCRITS, pas des présents : un poste tenu 24 h sur 24
        // demande environ 2,2 agents une fois comptés les trois relèves, les repos et les congés.
        // C'est ce facteur qui transforme un ratio de présence réglementaire en effectif de
        // tableau de service.
        //
        // RÉANIMATION — le seul cas où la France fixe un ratio : décret 2002-466, deux infirmiers
        // pour cinq patients et un aide-soignant pour quatre, en présence. Soit, en effectif
        // inscrit : 0,4 × 2,2 ≈ 0,88 IDE par lit, et 0,25 × 2,2 ≈ 0,55 AS par lit.
        /** Infirmiers par lit de réanimation ou de soins continus, en millièmes. */
        'nurses_per_intensive_bed' => 880,
        /** Aides-soignants par lit de réanimation ou de soins continus, en millièmes. */
        'aides_per_intensive_bed' => 550,
        //
        // HOSPITALISATION COMPLÈTE — aucun ratio national. Usage courant : un infirmier pour dix à
        // douze lits en journée, un pour quinze à vingt la nuit, soit environ 0,1 présent par lit
        // en moyenne sur le nycthémère. ×2,2 ⇒ ~0,22 IDE par lit. Les aides-soignants sont un peu
        // plus nombreux au lit du malade : un pour huit en journée ⇒ ~0,27 par lit.
        /** Infirmiers par lit d'hospitalisation, en millièmes. */
        'nurses_per_bed' => 220,
        /** Aides-soignants par lit d'hospitalisation, en millièmes. */
        'aides_per_bed' => 270,
        //
        // URGENCES — l'effectif suit le PASSAGE, pas le lit : une unité de six lits de déchocage
        // reçoit des dizaines de patients par jour. On pose donc une équipe forfaitaire, et non un
        // ratio par lit qui sous-estimerait grossièrement le service.
        /** Infirmiers du service d'ACCUEIL des urgences, forfait. */
        'emergency_nurses' => 14,
        /** Aides-soignants du service d'ACCUEIL des urgences, forfait. */
        'emergency_aides' => 10,
        /**
         * Les services dont l'effectif suit le PASSAGE et non le lit.
         *
         * NE PAS UTILISER `isEmergency()` POUR CE CHOIX, et c'est mesuré : ce drapeau marque les
         * unités qui REÇOIVENT des urgences — il sert à l'allocation des lits — et il est vrai pour
         * l'UHCD autant que pour l'accueil. L'utiliser donnait 14 infirmiers pour les 5 lits de
         * l'UHCD, soit un effectif de service d'accueil sur une unité d'hospitalisation courte.
         * Un drapeau juste pour un usage ne l'est pas pour un autre.
         */
        'flat_crew_slugs' => ['urgences'],
        /**
         * Plancher par unité ouverte : un poste tenu 24 h sur 24.
         *
         * UN RATIO PAR LIT SOUS-ESTIME LES PETITES UNITÉS, et le semis l'a montré : les 5 lits de
         * l'UHCD donnaient UN infirmier inscrit, soit un poste tenu huit heures par jour cinq jours
         * par semaine. Or une unité ouverte exige une présence permanente quelle que soit sa
         * capacité. Un poste permanent demande ~2,2 agents en théorie ; on en pose TROIS, parce
         * qu'un tableau de service à deux ne couvre ni les congés ni un arrêt.
         */
        'minimum_ward_staff' => 3,
        //
        /** Cadres de santé par unité : un par service, c'est la règle de fait. */
        'ward_managers_per_service' => 1,
        /** Secrétaires médicales par service. */
        'secretaries_per_service' => 1,

        // --- Le personnel administratif, PAR CENT LITS -------------------------------------
        //
        // Dimensionné sur la capacité de l'établissement et non par service : ces agents ne
        // tiennent pas d'unité de soins. Les équipes de pilotage restent FORFAITAIRES, parce
        // qu'un établissement a une direction et une cellule qualité quelle que soit sa taille.
        /** Agents d'admissions et de bureau des entrées, par cent lits. */
        'admissions_per_hundred_beds' => 4,
        /** Agents de facturation, par cent lits. */
        'billing_per_hundred_beds' => 3,
        /** Techniciens d'information médicale (PMSI), par cent lits. */
        'pmsi_per_hundred_beds' => 2,
        /** Encadrement de direction, forfait. */
        'management_staff' => 3,
        /** Cellule qualité et gestion des risques, forfait. */
        'quality_staff' => 2,
        /** Informatique et système d'information, forfait. */
        'it_staff' => 2,

        // --- Les incidents peropératoires, EN DIX-MILLIÈMES D'INTERVENTION --------------------
        //
        // POURQUOI DES INCIDENTS. Un programme opératoire où tout se passe bien n'apprend rien :
        // ce qu'un dossier doit pouvoir montrer, c'est une conversion, un saignement, une
        // perforation — et leurs conséquences sur la suite du séjour. Mais un incident est un
        // FAIT, pas une tournure de phrase : il change le code de l'acte, la durée, le diagnostic
        // relié, parfois le passage en réanimation. Ces coefficients décident s'il a lieu ; ses
        // effets sont décrits dans SurgicalIncidents.
        //
        // EN DIX-MILLIÈMES et non en pour cent, parce que les taux les plus bas sont inférieurs à
        // 1 % : une plaie d'organe à 0,5 % s'écrirait « 1 » en pour cent arrondi, soit le double.
        //
        // ⚠ CE SONT DES ORDRES DE GRANDEUR DE LITTÉRATURE, pas les chiffres d'un établissement.
        // Ils sont là pour qu'un jeu de données ressemble à une activité réelle, pas pour servir
        // de référence épidémiologique — et un enseignant qui veut d'autres taux les surcharge.
        //
        // CONVERSION — passage d'une voie cœlioscopique à la voie ouverte. Ce n'est PAS une
        // complication : c'est un changement d'acte, donc de code CCAM, et il n'a pas de code
        // CIM-10. Taux publiés : ~5 % en cholécystectomie, 5-10 % en appendicectomie, 10-15 % en
        // colectomie. On retient 5 %, appliqué aux seuls actes cœlioscopiques.
        'incident_conversion' => 500,
        // HÉMORRAGIE peropératoire (CIM-10 T81.0) : 1-3 % selon la chirurgie. On retient 2 %.
        'incident_hemorragie' => 200,
        // PLAIE D'ORGANE, perforation ou déchirure accidentelle (T81.2) : 0,3-1 %. On retient 0,5 %.
        'incident_plaie_organe' => 50,
        // INCIDENT ANESTHÉSIQUE (T88.2 choc, T88.5 autres) : 1-2 %, et FORTEMENT modulé par l'état
        // du patient — le score ASA est déjà porté par COperation. Base 1,5 %, multipliée pour un
        // ASA élevé (voir incidentAnaesthesiaFactorFor()).
        'incident_anesthesie' => 150,
        /**
         * Répartition du score ASA dans la population opérée, en pour mille.
         *
         * ASA 1 patient sain · 2 affection peu grave · 3 affection sévère · 4 menace vitale.
         * Ordres de grandeur d'une population chirurgicale générale. Le champ existait sur
         * COperation et restait VIDE : il fallait le renseigner pour que le risque anesthésique
         * puisse en dépendre, sans quoi l'incident se tirait à plat chez un patient sain comme
         * chez un patient en réanimation.
         *
         * LIMITE ASSUMÉE : la répartition est posée à plat, alors que l'ASA croît fortement avec
         * l'âge. Le corréler demanderait de passer l'âge du patient au tirage ; c'est faisable et
         * ce serait plus juste.
         */
        'asa_mix' => [1 => 300, 2 => 450, 3 => 200, 4 => 50],

        // --- Le dossier de soins -----------------------------------------------------------
        //
        // POURQUOI CES NOMBRES SONT PETITS, et c'est une décision de volume assumée. Un séjour
        // réel porte une transmission par relève — trois par jour — et une observation médicale
        // quotidienne. Appliqué au semis, cela donnerait sur `xl` (251 694 séjours, durée moyenne
        // de quelques jours) de l'ordre de 750 000 objets supplémentaires : le semis deviendrait
        // inutilisable, pour un gain de plausibilité nul au-delà des premiers écrits.
        //
        // On en pose donc QUELQUES-UNS par séjour : assez pour qu'un dossier ouvert à l'écran ne
        // soit pas vide, assez pour que le périmètre par unité soit vérifiable, et pas davantage.
        // Un modèle par jour de séjour serait plus réaliste et reste à faire ; il faudra alors un
        // plafond, sans quoi les séjours longs écraseraient le volume à eux seuls.
        //
        /** Observations médicales par séjour. */
        'observations_per_stay' => 2,
        /** Transmissions par séjour. */
        'transmissions_per_stay' => 3,
        /** Part des écrits marqués d'un degré élevé, en pour mille — ce qui attire l'œil. */
        'care_record_high_degree_rate' => 150,
        //
        // ⚠ L'INFECTION DU SITE OPÉRATOIRE N'EST PAS ICI, ET C'EST DÉLIBÉRÉ. Elle se manifeste à
        // J3-J10 : elle n'allonge pas l'intervention, elle prolonge le SÉJOUR et provoque parfois
        // une reprise ou une réadmission. C'est le seul des cinq incidents qui exige que le temps
        // passe, donc sa place est dans `simulate:advance`, qui fait vivre l'hôpital jour par
        // jour, et non dans un semis qui pose une photographie.
    ];

    /** @var array<string, mixed> */
    private $values;

    /**
     * @param array<string, mixed> $overrides
     *
     * @throws SeedException
     */
    public function __construct(array $overrides = [])
    {
        foreach (array_keys($overrides) as $key) {
            if (!array_key_exists($key, self::DEFAULTS)) {
                throw new SeedException(
                    "Paramètre clinique inconnu '{$key}'. Une faute de frappe produirait"
                    . ' silencieusement le comportement par défaut, donc elle est refusée.'
                );
            }
        }

        $this->values = $overrides + self::DEFAULTS;

        $this->assertConsistent();
    }

    /**
     * Ce qu'un décor ne doit pas pouvoir écrire, quelles que soient ses intentions.
     *
     * @throws SeedException
     */
    private function assertConsistent(): void
    {
        $total = array_sum($this->typeMix());

        if ($total !== 100) {
            throw new SeedException(
                "Le mix de types totalise {$total} % au lieu de cent : les proportions annoncées"
                . ' seraient fausses dans tous les tableaux de répartition.'
            );
        }

        if (($this->typeMix()['comp'] ?? 0) <= 0) {
            throw new SeedException(
                "Le mix ne comporte aucun séjour d'hospitalisation complète : aucun séjour ne pourrait"
                . " occuper de lit, et l'occupation visée serait inatteignable."
            );
        }

        foreach (['occupancy_band', 'icu_occupancy_band', 'emergency_occupancy_band'] as $band) {
            [$min, $max] = $this->values[$band];

            if ($min <= 0 || $min > $max || $max > 100) {
                throw new SeedException("Bande d'occupation '{$band}' incohérente : [{$min}, {$max}]");
            }
        }

        [$min, $max] = $this->operationMinutes();
        $shortest    = PHP_INT_MAX;

        foreach ($this->theatreSessions() as $session) {
            $minutes  = (strtotime('1970-01-01 ' . $session['fin']) - strtotime('1970-01-01 ' . $session['debut'])) / 60;
            $shortest = min($shortest, (int)$minutes);
        }

        // La capacité d'une plage est un PLAFOND EXACT et non une moyenne : elle ne tient que si la
        // durée maximale d'une intervention permet d'en caser le nombre annoncé.
        if ($max * $this->operationsPerSlot() > $shortest) {
            throw new SeedException(
                "Incohérence du bloc : {$this->operationsPerSlot()} interventions de {$max} minutes ne"
                . " tiennent pas dans une vacation de {$shortest} minutes. Le planificateur en"
                . ' accepterait plus que l\'écriture ne peut en placer, et les actes en trop seraient'
                . ' perdus.'
            );
        }

        if ($min <= 0 || $min > $max) {
            throw new SeedException("Durée d'intervention incohérente : [{$min}, {$max}]");
        }
    }

    /** @return array<string, int> */
    public function typeMix(): array
    {
        return $this->values['type_mix'];
    }

    /** @return array<string, int> */
    public function stateMix(): array
    {
        return $this->values['state_mix'];
    }

    /** @return array<string, int> */
    public function modeEntree(): array
    {
        return $this->values['mode_entree'];
    }

    /** @return array<string, int> */
    public function modeSortie(): array
    {
        return $this->values['mode_sortie'];
    }

    /** @return int[] [min, max] en jours */
    public function duration(string $type): array
    {
        return $this->values['durations'][$type] ?? [1, 3];
    }

    /** @return int[] [min, max] en jours */
    public function icuDuration(): array
    {
        return $this->values['icu_duration'];
    }

    /** @return int[] [min, max] en pourcentage */
    public function occupancyBand(): array
    {
        return $this->values['occupancy_band'];
    }

    /** @return int[] [min, max] en pourcentage */
    public function icuOccupancyBand(): array
    {
        return $this->values['icu_occupancy_band'];
    }

    /** @return int[] [min, max] en pourcentage */
    public function emergencyOccupancyBand(): array
    {
        return $this->values['emergency_occupancy_band'];
    }

    /** @return int[] [min, max] en minutes */
    public function operationMinutes(): array
    {
        return $this->values['operation_minutes'];
    }

    /** @return array<int, array{debut: string, fin: string}> */
    public function theatreSessions(): array
    {
        return $this->values['theatre_sessions'];
    }

    /** @return array<int, array{debut: string, fin: string}> */
    public function emergencySessions(): array
    {
        return $this->values['emergency_sessions'];
    }

    /** @return int[] [min, max] heures */
    public function arrivalHours(bool $emergency = false): array
    {
        return $emergency ? $this->values['emergency_arrival_hours'] : $this->values['arrival_hours'];
    }

    /**
     * Heures d'admission d'un séjour OPÉRÉ, hors urgence.
     *
     * Une urgence garde sa bande à elle : le bloc de garde couvre la nuit, et un patient opéré à
     * 3 h du matin est admis à 3 h du matin.
     *
     * @return int[]
     */
    public function surgicalArrivalHours(): array
    {
        return $this->values['surgical_arrival_hours'];
    }

    /**
     * Heures de sortie d'un séjour de JOUR opéré : après le réveil, en fin de journée.
     *
     * @return int[]
     */
    public function surgicalDischargeHours(): array
    {
        return $this->values['surgical_discharge_hours'];
    }

    public function adultAge(): int
    {
        return $this->values['adult_age'];
    }

    public function maxAge(): int
    {
        return $this->values['max_age'];
    }

    public function childMinAge(): int
    {
        return $this->values['child_min_age'];
    }

    public function femaleRate(): int
    {
        return $this->values['female_rate'];
    }

    public function marriedNameRate(): int
    {
        return $this->values['married_name_rate'];
    }

    public function sejoursPerPatient(): int
    {
        return $this->values['sejours_per_patient'];
    }

    public function mutationRate(): int
    {
        return $this->values['mutation_rate'];
    }

    public function operationRate(): int
    {
        return $this->values['operation_rate'];
    }

    public function surgicalOperationRate(): int
    {
        return $this->values['surgical_operation_rate'];
    }

    public function emergencyOperationRate(): int
    {
        return $this->values['emergency_operation_rate'];
    }

    public function operationsPerSlot(): int
    {
        return $this->values['operations_per_slot'];
    }

    public function targetSlotUsage(): int
    {
        return $this->values['target_slot_usage'];
    }

    public function sessionsPerDay(): int
    {
        return $this->values['sessions_per_day'];
    }

    public function maxOperatingRooms(): int
    {
        return $this->values['max_operating_rooms'];
    }

    public function maxOperatingShift(): int
    {
        return $this->values['max_operating_shift'];
    }

    public function doubleRoomDivisor(): int
    {
        return $this->values['double_room_divisor'];
    }

    public function futureWindowDivisor(): int
    {
        return $this->values['future_window_divisor'];
    }

    /**
     * Infirmiers inscrits pour un service, d'après sa capacité et sa nature.
     *
     * Les urgences échappent au ratio par lit : voir le commentaire des coefficients.
     */
    public function nursesFor(int $beds, bool $intensive, string $slug = ''): int
    {
        if ($this->hasFlatCrew($slug)) {
            return (int)$this->values['emergency_nurses'];
        }

        $per_mille = $intensive ? $this->values['nurses_per_intensive_bed'] : $this->values['nurses_per_bed'];

        return max((int)$this->values['minimum_ward_staff'], (int)round($beds * $per_mille / 1000));
    }

    public function aidesFor(int $beds, bool $intensive, string $slug = ''): int
    {
        if ($this->hasFlatCrew($slug)) {
            return (int)$this->values['emergency_aides'];
        }

        $per_mille = $intensive ? $this->values['aides_per_intensive_bed'] : $this->values['aides_per_bed'];

        return max((int)$this->values['minimum_ward_staff'], (int)round($beds * $per_mille / 1000));
    }

    /**
     * Ce service est-il dote d'une equipe forfaitaire plutot que d'un ratio par lit ?
     */
    public function hasFlatCrew(string $slug): bool
    {
        return $slug !== '' && in_array($slug, (array)$this->values['flat_crew_slugs'], true);
    }

    public function wardManagersPerService(): int
    {
        return (int)$this->values['ward_managers_per_service'];
    }

    public function secretariesPerService(): int
    {
        return (int)$this->values['secretaries_per_service'];
    }

    /**
     * Effectifs administratifs de l'établissement, par métier.
     *
     * @return array<string, int>
     */
    public function administrativeStaff(int $beds): array
    {
        $hundreds = max(1, (int)round($beds / 100));

        return [
            'admissions' => $hundreds * (int)$this->values['admissions_per_hundred_beds'],
            'facturation' => $hundreds * (int)$this->values['billing_per_hundred_beds'],
            'pmsi'       => $hundreds * (int)$this->values['pmsi_per_hundred_beds'],
            'direction'  => (int)$this->values['management_staff'],
            'qualite'    => (int)$this->values['quality_staff'],
            'si'         => (int)$this->values['it_staff'],
        ];
    }

    /**
     * Probabilité d'un incident peropératoire, en dix-millièmes.
     */
    public function incidentRate(string $incident): int
    {
        return (int)($this->values['incident_' . $incident] ?? 0);
    }

    /**
     * Facteur appliqué au risque anesthésique selon le score ASA.
     *
     * Un patient ASA 1 est un patient sain ; un ASA 4 porte une affection qui menace sa vie. Tirer
     * l'incident anesthésique à plat donnerait autant de complications chez l'un que chez l'autre,
     * ce qu'aucun anesthésiste ne reconnaîtrait — et c'est précisément la corrélation qu'un
     * enseignant veut faire voir.
     */
    public function observationsPerStay(): int
    {
        return (int)$this->values['observations_per_stay'];
    }

    public function transmissionsPerStay(): int
    {
        return (int)$this->values['transmissions_per_stay'];
    }

    public function careRecordHighDegreeRate(): int
    {
        return (int)$this->values['care_record_high_degree_rate'];
    }

    /**
     * @return array<int, int>
     */
    public function asaMix(): array
    {
        return (array)$this->values['asa_mix'];
    }

    public function incidentAnaesthesiaFactorFor(int $asa): int
    {
        switch ($asa) {
            case 0:
                // ASA INCONNU : on rend la base, et surtout PAS le facteur maximal. Sans ce cas,
                // un score non renseigné tombait dans `default` et attribuait le risque d'un
                // patient dont la vie est menacée à tout le monde — défaut constaté avant mise en
                // service, parce que le semis ne posait pas encore l'ASA.
                return 1;
            case 1:
                return 1;
            case 2:
                return 2;
            case 3:
                return 4;
            default:
                // ASA 4 et au-delà : le risque n'est plus du même ordre.
                return 8;
        }
    }

    public function practitionersPerService(): int
    {
        return $this->values['practitioners_per_service'];
    }

    public function surgeonsOnCall(): int
    {
        return $this->values['surgeons_on_call'];
    }

    public function anaesthetistsPerRoom(): int
    {
        return $this->values['anaesthetists_per_room'];
    }

    public function anaesthesiaSpare(): int
    {
        return $this->values['anaesthesia_spare'];
    }
}
