<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

use Ox\Cli\Seed\Decor\ClinicalMix;
use Ox\Core\CMbDT;

/**
 * Décide le scénario de tous les séjours, sans écrire une ligne.
 *
 * LE POINT CENTRAL : le nombre de séjours EN COURS n'est pas tiré de la répartition d'états, il
 * est CALIBRÉ sur la capacité en lits. Tirer cet état indépendamment donnerait un taux
 * d'occupation arbitraire — or c'est le premier chiffre qu'un formateur regarde sur un tableau
 * d'occupation, et une occupation à 12 % ou à 140 % décrédibilise tout le jeu de données. La
 * répartition d'états du profil ne sert donc que pour les séjours planifiés ; le reste est clos.
 *
 * Seuls les types qui occupent un lit nommé peuvent être « en cours » au sens de l'occupation :
 * un ambulatoire entre et sort le même jour et n'occupe pas de lit pour la nuit.
 */
final class SejourPlanner
{
    /**
     * Types qui occupent un lit nommé.
     *
     * `urg` en fait partie depuis que le catalogue ouvre des urgences et une UHCD : un séjour urgent
     * ne peut être hébergé que dans une unité qui porte l'un des drapeaux du socle, et il en existe
     * désormais deux. Les deux familles n'occupent pas les mêmes lits et n'ont ni les mêmes durées
     * ni la même bande d'occupation, d'où le traitement séparé partout dans cette classe.
     */
    private const BED_TYPES = ['comp', 'urg'];

    /** Type de séjour hébergé dans les unités d'accueil des urgences. */
    private const EMERGENCY_TYPE = 'urg';







    /** @var SeedContext */
    private $context;

    /** @var array<string, array{beds: int, intensive: bool, surgical: bool}> */
    private $services;

    /** @var string[] Jours où le bloc opère, au format 'Y-m-d', triés */
    private $operating_days = [];

    /**
     * @var array<string, array<string, int>> Interventions que chaque journée peut encore absorber,
     *      PAR SPÉCIALITÉ.
     *
     * Le planificateur ne se contente pas de savoir QUAND le bloc ouvre : il sait combien
     * d'interventions chaque journée peut prendre, et il décrémente. Sans ce compte, plusieurs
     * séjours se donnaient rendez-vous le même jour — les ambulatoires en particulier, qui n'ont
     * qu'une journée et donc aucun jour de repli — et les interventions en excès étaient perdues.
     *
     * Le détail par spécialité est venu ensuite : une vacation appartient à une équipe, et une
     * prothèse de hanche ne peut pas s'inscrire dans la vacation des gynécologues.
     */
    private $capacity = [];

    /**
     * @var int Interventions décidées puis abandonnées faute de plage atteignable.
     *
     * Compté et RAPPORTÉ. Un générateur qui ne rapporte que ses succès laisse croire que le jeu de
     * données est conforme à la commande : sur un profil medium, 36 interventions sur 478
     * disparaissaient ici sans que rien ne le signale, le compteur du builder ne voyant que celles
     * qu'il n'arrivait pas à écrire.
     */
    private $dropped_operations = 0;

    /**
     * @param array<string, array{beds: int, intensive: bool, surgical: bool}> $services
     * @param array<string, array<string, int>>                               $operating_days
     *        jour 'Y-m-d' => spécialité => nombre d'interventions qu'elle peut y opérer
     *
     * @throws SeedException
     */
    public function __construct(SeedContext $context, array $services, array $operating_days = [])
    {
        if ($services === []) {
            throw new SeedException('Aucun service : impossible de planifier des séjours');
        }

        ksort($operating_days);

        $this->context        = $context;
        $this->services       = $services;
        $this->capacity       = $operating_days;
        $this->operating_days = array_keys($operating_days);
    }

    /** Interventions décidées mais non programmables, faute de plage atteignable. */
    public function droppedOperations(): int
    {
        return $this->dropped_operations;
    }

    /**
     * Les proportions cliniques du décor. Raccourci : le planificateur les consulte à chaque séjour.
     *
     * @throws SeedException
     */
    private function mix(): ClinicalMix
    {
        return $this->context->decor()->clinicalMix();
    }

    /** Lits des unités de soins continus. */
    public function icuBedCount(): int
    {
        return $this->bedCountOf('intensive');
    }

    /** Lits des unités d'accueil des urgences, boxes et UHCD confondus. */
    public function emergencyBedCount(): int
    {
        return $this->bedCountOf('emergency');
    }

    /**
     * Nombre de séjours urgents devant occuper un lit d'accueil à l'instant de référence.
     *
     * Calibré sur la bande propre de ces unités, plus basse encore que celle des soins continus :
     * un box occupé n'est pas disponible pour le patient suivant.
     */
    public function emergencyCurrentCount(): int
    {
        [$min, $max] = $this->context->profile()->emergencyOccupancyBand();

        return (int)round($this->emergencyBedCount() * (($min + $max) / 2) / 100);
    }

    private function bedCountOf(string $family): int
    {
        $count = 0;

        foreach ($this->services as $service) {
            if ($this->familyOf($service) === $family) {
                $count += $service['beds'];
            }
        }

        return $count;
    }

    /**
     * Nombre de séjours devant occuper une place de soins continus à l'instant de référence.
     *
     * Calibré comme celui des lits d'hospitalisation complète, mais sur une bande plus basse : une
     * réanimation garde de la marge. Sans ce calcul, les unités de soins continus restaient
     * entièrement vides — 20 % de l'établissement inerte.
     */
    public function icuCurrentCount(): int
    {
        [$min, $max] = $this->context->profile()->icuOccupancyBand();

        return (int)round($this->icuBedCount() * (($min + $max) / 2) / 100);
    }

    /**
     * Nombre de lits d'hospitalisation complète : hors soins continus ET hors accueil des urgences.
     *
     * L'occupation se mesure sur ce périmètre. La réanimation, la surveillance continue et les boxes
     * d'urgence suivent chacun une logique d'occupation différente et fausseraient la bande visée.
     */
    public function wardBedCount(): int
    {
        return $this->bedCountOf('ward');
    }

    /**
     * Nombre de séjours devant être en cours à l'instant de référence.
     *
     * Calibré sur la capacité et sur le milieu de la bande d'occupation visée, pour que la
     * mesure tombe au centre plutôt qu'au bord.
     */
    public function currentCount(): int
    {
        [$min, $max] = $this->context->profile()->occupancyBand();

        return (int)round($this->wardBedCount() * (($min + $max) / 2) / 100);
    }

    /**
     * @return SejourPlan[]
     * @throws SeedException
     */
    public function plan(): array
    {
        $total = $this->context->profile()->sejours();
        $types = $this->drawTypes($total);

        // Seuls les séjours qui occupent un lit nommé peuvent être « en cours » au sens de
        // l'occupation. Ils se séparent en deux familles, qui n'occupent pas les mêmes lits :
        // l'hospitalisation (avec les soins continus) et l'accueil des urgences.
        $hospitalisation = [];
        $emergency       = [];
        $other_indexes   = [];

        foreach ($types as $index => $type) {
            if ($type === self::EMERGENCY_TYPE) {
                $emergency[] = $index;
            } elseif (in_array($type, self::BED_TYPES, true)) {
                $hospitalisation[] = $index;
            } else {
                $other_indexes[] = $index;
            }
        }

        // UN DÉCOR NE PEUT PAS ANNONCER DES URGENCES SANS UNITÉ POUR LES ACCUEILLIR. Le socle refuse
        // l'affectation d'un séjour urgent dans un service qui ne porte aucun des drapeaux d'accueil
        // (« Emergency stay cant be in non emergency service ») : sans ce contrôle, un décor mal réglé
        // produirait des séjours urgents sans lit, ou ferait échouer l'écriture séjour après séjour.
        if ($emergency !== [] && $this->emergencyBedCount() === 0) {
            throw new SeedException(
                'Décor incohérent : le mix annonce des séjours urgents, mais aucune unité du catalogue'
                . " n'est capable de les héberger. Ajouter une unité d'accueil des urgences, ou retirer"
                . " le type 'urg' du mix."
            );
        }

        $ward_current      = $this->currentCount();
        $icu_current       = $this->icuCurrentCount();
        $emergency_current = $this->emergencyCurrentCount();

        $this->assertCapacity($ward_current, $icu_current, count($hospitalisation), 'hospitalisation');
        $this->assertCapacity(0, $emergency_current, count($emergency), 'accueil des urgences');

        $random = $this->context->stream('etats-sejour');

        $states   = array_fill(0, count($types), SejourPlan::STATE_CLOSED);
        $services = [];

        // Les séjours EN COURS sont répartis service par service, proportionnellement aux lits.
        // Un tirage uniforme donnait des écarts visibles — quatre services à 95 % et deux à 50 %
        // sur un même établissement — parce que rien ne rattachait le nombre de séjours d'un
        // service à sa capacité.
        $current_indexes = $random->take($hospitalisation, $ward_current + $icu_current);
        $quota           = $this->currentServiceQuota($ward_current, $icu_current);

        foreach ($current_indexes as $position => $index) {
            $states[$index]   = SejourPlan::STATE_CURRENT;
            $services[$index] = $quota[$position];
        }

        // Et les urgences ont leur propre calibrage, sur leur propre bande : sans lui, les boxes
        // et l'UHCD seraient vides alors que 10 % des séjours s'y rapportent.
        $emergency_indexes = $random->take($emergency, $emergency_current);
        $emergency_quota   = $this->emergencyServiceQuota($emergency_current);

        foreach ($emergency_indexes as $position => $index) {
            $states[$index]   = SejourPlan::STATE_CURRENT;
            $services[$index] = $emergency_quota[$position];
        }

        $current_indexes = array_merge($current_indexes, $emergency_indexes);

        // Les planifiés se répartissent sur tout ce qui n'est pas déjà en cours. Un séjour urgent,
        // lui, ne se programme pas : c'est la définition d'une urgence.
        $mix           = $this->context->profile()->sejourStateMix();
        $planned_rate  = $mix[SejourPlan::STATE_PLANNED] ?? 10;
        $planned_count = (int)round(count($types) * $planned_rate / 100);

        $available = array_values(array_diff(array_merge($hospitalisation, $other_indexes), $current_indexes));
        sort($available);

        foreach ($random->take($available, min($planned_count, count($available))) as $index) {
            $states[$index] = SejourPlan::STATE_PLANNED;
        }

        $plans = [];
        foreach ($types as $index => $type) {
            $plans[] = $this->planOne($type, $states[$index], $services[$index] ?? null);
        }

        return $plans;
    }

    /**
     * Refuse un profil dont la capacité ne peut pas être remplie par le mix de types.
     *
     * @throws SeedException
     */
    private function assertCapacity(int $first, int $second, int $available, string $family): void
    {
        if ($first + $second <= $available) {
            return;
        }

        throw new SeedException(
            'Capacité incohérente : ' . ($first + $second) . " séjours devraient être en cours pour"
            . " atteindre la bande d'occupation de l'{$family}, mais le mix de types n'en produit que"
            . " {$available} susceptibles d'y occuper un lit. Augmenter --sejours, ou réduire --lits."
        );
    }

    /**
     * Unités d'accueil des séjours urgents en cours, proportionnellement à leurs lits.
     *
     * @return string[]
     * @throws SeedException
     */
    private function emergencyServiceQuota(int $count): array
    {
        return $this->context->stream('quota-urgences')->shuffle($this->quotaFor($count, 'emergency'));
    }

    /**
     * Services d'accueil des séjours en cours, un par séjour, proportionnellement aux lits.
     *
     * La liste est mélangée pour que l'ordre d'attribution ne corrèle pas avec l'ordre des index de
     * séjours, mais sa COMPOSITION est déterminée par les capacités : c'est ce qui fait qu'un
     * service de vingt lits reçoit deux fois plus de patients qu'un service de dix.
     *
     * @return string[]
     * @throws SeedException
     */
    private function currentServiceQuota(int $ward_current, int $icu_current): array
    {
        $quota = array_merge(
            $this->quotaFor($ward_current, 'ward'),
            $this->quotaFor($icu_current, 'intensive')
        );

        return $this->context->stream('quota-services')->shuffle($quota);
    }

    /**
     * @return string[]
     * @throws SeedException
     */
    private function quotaFor(int $count, string $family): array
    {
        $slugs = [];
        $beds  = [];

        foreach ($this->services as $slug => $service) {
            if ($this->familyOf($service) !== $family) {
                continue;
            }

            $slugs[] = $slug;
            $beds[]  = $service['beds'];
        }

        if ($slugs === [] || $count === 0) {
            return [];
        }

        // Répartition proportionnelle aux lits, le reste d'arrondi allant aux plus grandes unités.
        $total  = array_sum($beds) ?: 1;
        $shares = [];

        foreach (array_keys($slugs) as $i) {
            $shares[$i] = (int)floor($count * $beds[$i] / $total);
        }

        $remainder = $count - array_sum($shares);

        arsort($beds);
        foreach (array_keys($beds) as $i) {
            if ($remainder <= 0) {
                break;
            }

            $shares[$i]++;
            $remainder--;
        }

        $quota = [];
        foreach ($slugs as $i => $slug) {
            for ($n = 0; $n < $shares[$i]; $n++) {
                $quota[] = $slug;
            }
        }

        return $quota;
    }

    /**
     * Famille d'un service : hospitalisation, soins continus, ou accueil des urgences.
     *
     * Les trois sont exclusives, et chacune a sa bande d'occupation et ses durées de séjour. Les
     * confondre donnait un taux d'occupation qui ne correspondait à aucune des trois.
     *
     * @param array{beds: int, intensive: bool, surgical: bool, emergency?: bool} $service
     */
    private function familyOf(array $service): string
    {
        if ($service['emergency'] ?? false) {
            return 'emergency';
        }

        return $service['intensive'] ? 'intensive' : 'ward';
    }

    /**
     * Types tirés en respectant EXACTEMENT les proportions du profil.
     *
     * Un tirage pondéré indépendant par séjour donnerait les bonnes proportions en moyenne, mais
     * pas sur 300 séjours : l'écart se verrait sur un tableau de répartition. On construit donc
     * la liste par comptes, puis on la mélange.
     *
     * @return string[]
     * @throws SeedException
     */
    private function drawTypes(int $total): array
    {
        $mix = $this->context->profile()->sejourTypeMix();

        $counts = [];
        $sum    = 0;
        foreach ($mix as $type => $percent) {
            $counts[$type] = (int)floor($total * $percent / 100);
            $sum           += $counts[$type];
        }

        // Le reste de l'arrondi va aux types les plus fréquents, dans l'ordre du mix.
        $remaining = $total - $sum;
        $keys      = array_keys($mix);
        for ($i = 0; $i < $remaining; $i++) {
            $counts[$keys[$i % count($keys)]]++;
        }

        $types = [];
        foreach ($counts as $type => $count) {
            for ($i = 0; $i < $count; $i++) {
                $types[] = $type;
            }
        }

        return $this->context->stream('types-sejour')->shuffle($types);
    }

    /**
     * @param string|null $forced_service Service imposé par le quota des séjours en cours
     *
     * @throws SeedException
     */
    private function planOne(string $type, string $state, ?string $forced_service = null): SejourPlan
    {
        $random  = $this->context->stream('dates-sejour');
        $service = $forced_service ?? $this->pickService($type);

        $family    = $this->familyOf($this->services[$service]);
        $intensive = $family === 'intensive';
        $emergency = $family === 'emergency';

        // Les durées appartiennent au profil : c'est d'elles que se déduit le nombre de séjours
        // nécessaire pour soutenir l'occupation visée, et les deux ne doivent pas pouvoir diverger.
        $profile  = $this->context->profile();
        $duration = $intensive ? $profile->icuSejourDuration() : $profile->sejourDuration($type);
        $days     = $random->int($duration[0], $duration[1]);

        $needs_bed = in_array($type, self::BED_TYPES, true);
        $segments  = 1;

        // Pas de mutation au départ d'une unité de soins continus. La raison n'est pas clinique —
        // un transfert de réanimation vers un lit d'hospitalisation est au contraire le parcours
        // ordinaire — mais arithmétique : l'effectif de ces unités est calibré À L'INSTANT DE
        // RÉFÉRENCE, et une mutation placée au milieu du séjour sort le patient de l'unité dont on
        // mesure l'occupation. Mesuré : 53 % au lieu des 65 % visés, sous la bande annoncée.
        if ($needs_bed && !$intensive && $days >= 4 && $random->chanceInThousand($this->mix()->mutationRate())) {
            $segments = 2;
        }

        // Deux chemins vers le bloc, et deux réserves de vacations : le programme opératoire d'une
        // spécialité, ou la garde du bloc d'urgence.
        $with_operation = false;
        $pool           = null;

        if ($emergency) {
            $with_operation = $random->chanceInThousand($this->mix()->emergencyOperationRate());
            $pool           = SejourPlan::EMERGENCY_POOL;
        } elseif ($this->services[$service]['surgical'] && in_array($type, ['comp', 'ambu'], true)) {
            $with_operation = $random->chanceInThousand($this->mix()->surgicalOperationRate());
            $pool           = $service;
        }

        // Le fait d'opérer est décidé AVANT les dates, et il les contraint : un patient opéré au bloc
        // programmé est admis avant son ouverture.
        [$entree_prevue, $sortie_prevue, $entree_reelle, $sortie_reelle] =
            $this->drawDates($state, $days, $random, $emergency, $with_operation);

        $operation_day = null;
        if ($with_operation) {
            [$operation_day, $shift] = $this->scheduleOperation(
                $state,
                (string)$pool,
                $entree_prevue,
                $sortie_prevue
            );

            if ($operation_day === null) {
                $this->dropped_operations++;
            }

            if ($shift !== 0) {
                $entree_prevue = $this->shiftDays($entree_prevue, $shift);
                $sortie_prevue = $this->shiftDays($sortie_prevue, $shift);
                $entree_reelle = $this->shiftDays($entree_reelle, $shift);
                $sortie_reelle = $this->shiftDays($sortie_reelle, $shift);
            }
        }

        $mode_entree = $type === 'urg'
            ? '7'
            : (string)$random->pickWeighted($this->mix()->modeEntree());

        $mode_sortie = $sortie_reelle === null
            ? null
            : (string)$random->pickWeighted($this->mix()->modeSortie());

        return new SejourPlan(
            $type,
            $state,
            $service,
            $entree_prevue,
            $sortie_prevue,
            $entree_reelle,
            $sortie_reelle,
            $needs_bed,
            $segments,
            $with_operation,
            $mode_entree,
            $mode_sortie,
            $operation_day,
            $operation_day === null ? null : $pool
        );
    }

    /**
     * Jour opératoire du séjour, et décalage à appliquer au séjour pour l'atteindre.
     *
     * C'est ici que se joue l'inversion de causalité : plutôt que de placer le séjour puis de
     * chercher une plage le jour de l'entrée — un tiers des interventions tombaient alors un
     * week-end et disparaissaient — on cale le séjour sur un jour où le bloc opère.
     *
     * Le sens du décalage dépend de l'état, et ce n'est pas un détail : reculer un séjour clos le
     * garde dans le passé, avancer un séjour planifié le garde dans l'avenir. Un séjour EN COURS,
     * lui, ne se décale pas — il encadre l'instant de référence, et le déplacer lui ferait perdre
     * cette propriété, qui est celle dont dépend le taux d'occupation.
     *
     * @return array{0: string|null, 1: int} jour opératoire (null si aucun) et décalage en jours
     * @throws SeedException
     */
    private function scheduleOperation(
        string $state,
        string $speciality,
        string $entree,
        string $sortie
    ): array {
        if ($this->operating_days === []) {
            return [null, 0];
        }

        $inside = $this->operatingDaysBetween(
            substr($entree, 0, 10),
            substr($sortie, 0, 10),
            $speciality
        );

        if ($inside !== []) {
            // Le premier jour du séjour où l'équipe a encore de la place : on opère tôt dans
            // l'hospitalisation, et les jours suivants servent à la surveillance post-opératoire.
            $this->capacity[$inside[0]][$speciality]--;

            return [$inside[0], 0];
        }

        if ($state === SejourPlan::STATE_CURRENT) {
            return [null, 0];
        }

        $entry_day = substr($entree, 0, 10);

        // Le sens PRÉFÉRÉ dépend de l'état — reculer un séjour clos le garde dans le passé, avancer
        // un séjour planifié le garde dans l'avenir — mais l'autre sens est essayé ensuite, à
        // condition qu'il préserve l'état. Ne tenter qu'une direction faisait abandonner une
        // intervention dès que les jours voisins étaient complets.
        foreach ($state === SejourPlan::STATE_PLANNED ? [1, -1] : [-1, 1] as $direction) {
            $target = $this->operatingDayFrom($entry_day, $direction, $speciality);

            if ($target === null) {
                continue;
            }

            $shift = $this->dayDelta($entry_day, $target);

            // Cible hors de portée : le bloc n'ouvre pas — ou n'a plus de place — dans la semaine
            // qui entoure ce séjour.
            if ($shift === 0 && $target !== $entry_day) {
                continue;
            }

            if (!$this->keepsItsState($state, $shift, $entree, $sortie)) {
                continue;
            }

            $this->capacity[$target][$speciality]--;

            return [$target, $shift];
        }

        return [null, 0];
    }

    /**
     * Le séjour décalé reste-t-il dans son état ?
     *
     * Un séjour clos doit rester entièrement passé et un séjour planifié entièrement futur : un
     * décalage qui leur ferait franchir l'instant de référence produirait un séjour clos dans
     * l'avenir, ou une admission programmée déjà passée.
     */
    private function keepsItsState(string $state, int $shift, string $entree, string $sortie): bool
    {
        $now = $this->context->now();

        if ($state === SejourPlan::STATE_PLANNED) {
            return strcmp((string)$this->shiftDays($entree, $shift), $now) > 0;
        }

        return strcmp((string)$this->shiftDays($sortie, $shift), $now) < 0;
    }

    /**
     * Jours où LA SPÉCIALITÉ opère, compris dans l'intervalle et ayant encore de la place, bornes
     * incluses.
     *
     * @return string[]
     */
    private function operatingDaysBetween(string $from, string $to, string $speciality): array
    {
        $days = [];

        // La liste est triée : on s'arrête dès qu'on l'a dépassée. Sans cela, chaque séjour
        // chirurgical d'un profil `xl` parcourait les 3 000 jours d'ouverture du bloc.
        foreach ($this->operating_days as $day) {
            if (strcmp($day, $to) > 0) {
                break;
            }

            if (strcmp($day, $from) >= 0 && ($this->capacity[$day][$speciality] ?? 0) > 0) {
                $days[] = $day;
            }
        }

        return $days;
    }

    /**
     * Jour le plus proche de $day, dans la direction demandée et $day compris, où la spécialité
     * opère et a encore de la place.
     *
     * @param int $direction +1 vers l'avenir, -1 vers le passé
     */
    private function operatingDayFrom(string $day, int $direction, string $speciality): ?string
    {
        $found = null;

        foreach ($this->operating_days as $candidate) {
            if (($this->capacity[$candidate][$speciality] ?? 0) <= 0) {
                continue;
            }

            if ($direction > 0 && strcmp($candidate, $day) >= 0) {
                return $candidate;
            }

            if ($direction < 0 && strcmp($candidate, $day) <= 0) {
                $found = $candidate;
            }
        }

        return $found;
    }

    /**
     * Écart en jours entre deux dates, borné au décalage admissible.
     *
     * Compté par pas d'un jour et non par différence d'horodatages : `CMbDT::daysRelative()`
     * divise des timestamps par 86400, ce qui se décale d'un jour au passage à l'heure d'été.
     * Sur une opération, un jour d'écart, c'est une plage manquée.
     */
    private function dayDelta(string $from, string $to): int
    {
        if ($from === $to) {
            return 0;
        }

        $forward = $from;
        $back    = $from;

        for ($i = 1; $i <= $this->mix()->maxOperatingShift(); $i++) {
            $forward = CMbDT::date('+1 day', $forward);
            if ($forward === $to) {
                return $i;
            }

            $back = CMbDT::date('-1 day', $back);
            if ($back === $to) {
                return -$i;
            }
        }

        return 0;
    }

    /** Décale un instant de $days jours en conservant son heure. */
    private function shiftDays(?string $moment, int $days): ?string
    {
        if ($moment === null || $days === 0) {
            return $moment;
        }

        return CMbDT::date("{$days} days", substr($moment, 0, 10)) . substr($moment, 10);
    }

    /**
     * @return array{0: string, 1: string, 2: string|null, 3: string|null}
     * @throws SeedException
     */
    private function drawDates(
        string $state,
        int $days,
        SeedRandom $random,
        bool $emergency = false,
        bool $with_operation = false
    ): array {
        // Une urgence n'attend pas les heures ouvrables : elle arrive à n'importe quelle heure, et
        // c'est ce qui rend un tableau de garde crédible. Les admissions ordinaires, elles, se font
        // dans la journée.
        $arrival = $this->mix()->arrivalHours($emergency);

        // UN SÉJOUR OPÉRÉ AU BLOC PROGRAMMÉ EST ADMIS AVANT SON OUVERTURE. Les vacations se tiennent
        // de 8 h à 18 h 30 ; une admission tirée jusqu'à 20 h plaçait l'intervention hors des bornes
        // du séjour, ce que le socle refuse au codage — et qui était de toute façon absurde.
        $day_case = $with_operation && !$emergency && $days === 0;

        if ($with_operation && !$emergency) {
            $arrival = $this->mix()->surgicalArrivalHours();
        }

        $now    = $this->context->now();
        $window = $this->context->profile()->windowMonths();

        if ($state === SejourPlan::STATE_PLANNED) {
            // Admissions à venir : quelques semaines devant, pas la profondeur de l'historique.
            $horizon = $this->context->profile()->futureHorizonMonths();
            $offset  = $random->int(1, max(1, $horizon * 30));

            $entree = CMbDT::dateTime("+{$offset} days", $now);
            // Une admission programmée se fait en journée ; opérée, elle suit la bande chirurgicale
            // comme les autres, sans quoi une intervention à venir tomberait avant l'admission.
            $entree = $with_operation && !$emergency
                ? $this->atHour($entree, $random->int($arrival[0], $arrival[1]))
                : $this->atHour($entree, $random->int(8, 17));

            $sortie = $this->closingMoment($entree, $days, $random, $day_case);

            return [$entree, $sortie, null, null];
        }

        if ($state === SejourPlan::STATE_CURRENT) {
            // Déjà entré, pas encore sorti : l'entrée doit être dans le passé, à une profondeur
            // cohérente avec la durée prévue, sinon un séjour « en cours » de 2 jours entré il y
            // a 3 semaines apparaîtrait comme une anomalie.
            $elapsed = $days === 0 ? 0 : $random->int(0, $days);
            $entree  = CMbDT::dateTime("-{$elapsed} days", $now);
            $entree  = $this->atHour($entree, $random->int($arrival[0], $arrival[1]));

            if (strcmp($entree, $now) >= 0) {
                $entree = $this->onTheHour(CMbDT::dateTime('-2 hours', $now));
            }

            $sortie_prevue = $this->closingMoment($entree, $days, $random, $day_case);

            // La sortie prévue doit rester devant nous : le lit est réservé jusque-là.
            if (strcmp($sortie_prevue, $now) <= 0) {
                $sortie_prevue = $this->onTheHour(CMbDT::dateTime('+1 day', $now));
            }

            return [$entree, $sortie_prevue, $entree, null];
        }

        // Clos : entièrement dans la fenêtre passée.
        $span   = max(1, $window * 30 - $days - 1);
        $offset = $random->int($days + 1, $span);
        $entree = CMbDT::dateTime("-{$offset} days", $now);
        $entree = $this->atHour($entree, $random->int($arrival[0], $arrival[1]));
        $sortie = $this->closingMoment($entree, $days, $random, $day_case);

        return [$entree, $sortie, $entree, $sortie];
    }

    /**
     * Moment de sortie : le même jour pour un ambulatoire, sinon $days jours plus tard.
     *
     * Pour un ambulatoire l'heure de sortie est TOUJOURS après l'heure d'entrée et dans la même
     * journée calendaire — c'est l'invariant 5, et il se joue ici.
     *
     * @throws SeedException
     */
    private function closingMoment(
        string $entree,
        int $days,
        SeedRandom $random,
        bool $day_case = false
    ): string {
        if ($days === 0) {
            $day       = substr($entree, 0, 10);
            $entry_hour = (int)substr($entree, 11, 2);

            // Un ambulatoire OPÉRÉ sort le soir, après son réveil : sans cela il pouvait sortir à
            // 9 h, avant même la vacation de l'après-midi où son intervention était prévue.
            if ($day_case) {
                $band      = $this->mix()->surgicalDischargeHours();
                $exit_hour = $random->int(max($band[0], $entry_hour + 2), max($band[1], $entry_hour + 2));
            } else {
                $exit_hour = $random->int(min(23, $entry_hour + 2), 23);
            }

            $exit_hour = min(23, $exit_hour);

            return $day . ' ' . sprintf('%02d', $exit_hour) . ':' . ($exit_hour === 23 ? '30' : '00') . ':00';
        }

        $exit = CMbDT::dateTime("+{$days} days", $entree);

        return $this->atHour($exit, $random->int(9, 18));
    }

    private function atHour(string $moment, int $hour): string
    {
        return substr($moment, 0, 10) . sprintf(' %02d:00:00', $hour);
    }

    /**
     * Ramène un instant à l'heure ronde.
     *
     * Indispensable sur les dates DÉRIVÉES DE L'INSTANT DE RÉFÉRENCE : celui-ci porte les secondes
     * de l'horloge, et « maintenant + 1 jour » donnait des sorties prévues du genre 20:36:17.
     * Or CSejour tronque ces dates à la minute en base, si bien que l'affectation, elle, gardait
     * les secondes et finissait 17 secondes APRÈS la sortie du séjour — une violation de
     * l'invariant 3 (affectations incluses dans les bornes du séjour), constatée sur un profil
     * medium alors que les tests, qui figent l'instant de référence à une seconde ronde, ne
     * pouvaient pas la voir.
     *
     * Accessoirement, une sortie prévue à 20:36:17 n'a aucun sens : on planifie une sortie à
     * l'heure.
     */
    private function onTheHour(string $moment): string
    {
        return substr($moment, 0, 13) . ':00:00';
    }

    /**
     * Service d'accueil, tiré parmi ceux qui peuvent recevoir ce type de séjour.
     *
     * Un séjour qui occupe un lit ne va pas en soins continus par tirage ordinaire : ces unités
     * ont leur propre logique d'admission, et les y envoyer au hasard ferait grimper leur
     * occupation au-delà du plausible.
     *
     * @throws SeedException
     */
    private function pickService(string $type): string
    {
        $random = $this->context->stream('services-sejour');

        // Un séjour urgent ne peut être hébergé QUE dans une unité d'accueil : le socle le refuse
        // ailleurs. Réciproquement, une admission ordinaire ne va pas dans un box d'urgence.
        $wanted = $type === self::EMERGENCY_TYPE ? 'emergency' : 'ward';

        $candidates = [];
        foreach ($this->services as $slug => $service) {
            // Les unités de soins continus ne se remplissent PAS par tirage ordinaire : leur
            // effectif est calibré sur leur propre bande d'occupation, et les séjours qu'elles
            // reçoivent leur sont imposés par le quota (cf. currentServiceQuota). Les mêler au
            // tirage général ferait grimper leur occupation au-delà du plausible, et leurs lits
            // sont pour la même raison exclus de la mesure d'occupation d'hospitalisation.
            if ($this->familyOf($service) === $wanted) {
                $candidates[] = $slug;
            }
        }

        if ($candidates === []) {
            $candidates = array_keys($this->services);
        }

        return (string)$random->pick($candidates);
    }
}
