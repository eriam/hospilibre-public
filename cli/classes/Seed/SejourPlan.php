<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

/**
 * Scénario d'un séjour, décidé AVANT toute écriture.
 *
 * Séparer la décision de l'écriture est ce qui rend le cycle de vie temporel testable sans base :
 * les invariants « un ambulatoire entre et sort le même jour », « les affectations tiennent dans
 * les bornes réelles », « l'occupation instantanée tombe dans la bande visée » portent sur des
 * dates, pas sur des lignes SQL. Les vérifier à vide coûte quelques millisecondes ; les vérifier
 * après écriture coûte des minutes.
 *
 * ÉTATS, et ce qu'ils signifient pour l'application (cf. §5 du design) :
 *  - CLOSED  : entrée et sortie réelles passées — historique et statistiques ;
 *  - CURRENT : entrée réelle passée, sortie réelle nulle — occupe RÉELLEMENT un lit maintenant ;
 *  - PLANNED : aucune date réelle, entrée prévue future — admissions à venir.
 */
final class SejourPlan
{
    public const STATE_CLOSED  = 'closed';
    public const STATE_CURRENT = 'current';
    public const STATE_PLANNED = 'planned';

    /**
     * Types qui ne produisent AUCUNE affectation : le patient n'est pas hébergé, même le temps
     * d'une journée. Un externe ou une consultation ne passe pas par un service d'hébergement.
     *
     * `urg` n'en fait PLUS partie. Il en faisait partie tant que l'établissement n'avait pas
     * d'unité d'accueil : CAffectation::check() refuse l'affectation d'un séjour urgent dans un
     * service qui ne porte aucun des drapeaux urgence / radiologie / obstétrique / UHCD
     * (« Emergency stay cant be in non emergency service »). Le catalogue ouvre désormais des
     * urgences et une UHCD, donc les séjours urgents sont hébergés comme les autres — ils ne sont
     * plus des séjours sans lit ni acte, ce qui représentait 10 % du jeu de données.
     */
    public const NO_AFFECTATION_TYPES = ['exte', 'seances', 'consult'];

    /**
     * Types que le socle EXEMPTE du contrôle de collision entre séjours d'un même patient.
     *
     * Recopie de CSejour::$_not_collides. Un même patient peut donc cumuler plusieurs de ces
     * séjours sur une même période, et le seed n'a pas à leur réserver de créneau.
     */
    public const NOT_COLLIDING_TYPES = ['urg', 'consult', 'seances', 'exte'];

    /**
     * Clé de la réserve de vacations du BLOC D'URGENCE.
     *
     * Les vacations du bloc programmé appartiennent chacune à une spécialité ; celles du bloc d'urgence
     * appartiennent au chirurgien de garde et forment une réserve commune, que toute intervention en
     * urgence peut utiliser. C'est une notion du moteur et non du décor : un décor décide s'il a un bloc
     * d'urgence, pas comment le planificateur compte ses places.
     */
    public const EMERGENCY_POOL = '@urgences';

    /** @var string */
    private $type;

    /** @var string */
    private $state;

    /** @var string slug de service */
    private $service;

    /** @var string */
    private $entree_prevue;

    /** @var string */
    private $sortie_prevue;

    /** @var string|null */
    private $entree_reelle;

    /** @var string|null */
    private $sortie_reelle;

    /** @var bool Le séjour occupe-t-il un lit nommé ? */
    private $needs_bed;

    /** @var int Nombre d'affectations successives (une mutation de service en produit deux) */
    private $segments;

    /** @var bool */
    private $with_operation;

    /** @var string */
    private $mode_entree;

    /** @var string|null */
    private $mode_sortie;

    /**
     * @var string|null Jour de l'intervention, au format 'Y-m-d'.
     *
     * Décidé par le planificateur et non par le builder : c'est l'inversion de causalité qui
     * supprime les interventions perdues. Le premier jet plaçait le séjour, puis cherchait une
     * plage le jour de l'entrée — un tiers des interventions tombaient un week-end ou hors période
     * programmée et disparaissaient. Dans la réalité, c'est le séjour qui se planifie autour de
     * l'intervention.
     */
    private $operation_day;

    /**
     * @var string|null Réserve de vacations utilisable par l'intervention.
     *
     * Une vacation du bloc programmé appartient à une spécialité ; celles du bloc d'urgence
     * appartiennent à l'équipe de garde et forment une réserve commune. Porter cette distinction
     * dans le plan permet au planificateur de compter la capacité au bon endroit, et au builder
     * d'aller chercher la plage dans le bon bloc.
     */
    private $operation_pool;

    public function __construct(
        string $type,
        string $state,
        string $service,
        string $entree_prevue,
        string $sortie_prevue,
        ?string $entree_reelle,
        ?string $sortie_reelle,
        bool $needs_bed,
        int $segments,
        bool $with_operation,
        string $mode_entree,
        ?string $mode_sortie,
        ?string $operation_day = null,
        ?string $operation_pool = null
    ) {
        $this->type           = $type;
        $this->state          = $state;
        $this->service        = $service;
        $this->entree_prevue  = $entree_prevue;
        $this->sortie_prevue  = $sortie_prevue;
        $this->entree_reelle  = $entree_reelle;
        $this->sortie_reelle  = $sortie_reelle;
        $this->needs_bed      = $needs_bed;
        $this->segments       = $segments;
        $this->with_operation = $with_operation;
        $this->mode_entree    = $mode_entree;
        $this->mode_sortie    = $mode_sortie;
        $this->operation_day  = $operation_day;
        $this->operation_pool = $operation_pool;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function state(): string
    {
        return $this->state;
    }

    public function service(): string
    {
        return $this->service;
    }

    public function entreePrevue(): string
    {
        return $this->entree_prevue;
    }

    public function sortiePrevue(): string
    {
        return $this->sortie_prevue;
    }

    public function entreeReelle(): ?string
    {
        return $this->entree_reelle;
    }

    public function sortieReelle(): ?string
    {
        return $this->sortie_reelle;
    }

    public function needsBed(): bool
    {
        return $this->needs_bed;
    }

    /**
     * Le séjour produit-il une affectation ?
     *
     * Un ambulatoire en produit une, au niveau du SERVICE et sans lit : `CAffectation::lit_id`
     * n'est pas notNull, ce qui modélise exactement une prise en charge sans occupation de lit
     * pour la nuit. Un externe n'en produit aucune.
     */
    public function createsAffectation(): bool
    {
        return !in_array($this->type, self::NO_AFFECTATION_TYPES, true);
    }

    /**
     * Ce séjour entre-t-il dans le contrôle de collision du socle ?
     */
    public function collidesWithOtherStays(): bool
    {
        return !in_array($this->type, self::NOT_COLLIDING_TYPES, true);
    }

    public function segments(): int
    {
        return $this->segments;
    }

    public function withOperation(): bool
    {
        return $this->with_operation && $this->operation_day !== null;
    }

    /** Jour de l'intervention, garanti être un jour d'ouverture du bloc. */
    public function operationDay(): ?string
    {
        return $this->operation_day;
    }

    /**
     * Réserve de vacations dans laquelle l'intervention doit s'inscrire : la spécialité qui opère
     * pour une intervention programmée, le bloc d'urgence pour une intervention en urgence.
     */
    public function operationPool(): ?string
    {
        return $this->operation_pool;
    }

    /** L'intervention relève-t-elle du bloc d'urgence ? */
    public function operationIsEmergency(): bool
    {
        return $this->operation_pool === self::EMERGENCY_POOL;
    }

    public function modeEntree(): string
    {
        return $this->mode_entree;
    }

    public function modeSortie(): ?string
    {
        return $this->mode_sortie;
    }

    public function isCurrent(): bool
    {
        return $this->state === self::STATE_CURRENT;
    }

    public function isPlanned(): bool
    {
        return $this->state === self::STATE_PLANNED;
    }

    public function isClosed(): bool
    {
        return $this->state === self::STATE_CLOSED;
    }

    /**
     * Bornes réelles de présence, pour placer les affectations.
     *
     * Un séjour en cours n'a pas de sortie réelle : on retient la sortie PRÉVUE, parce qu'une
     * affectation exige une fin (`CAffectation::sortie` est notNull). C'est aussi ce qu'affiche
     * l'application : le lit est réservé jusqu'à la sortie prévue.
     *
     * @return array{0: string, 1: string}|null null quand le séjour n'a pas commencé
     */
    public function stayBounds(): ?array
    {
        if ($this->entree_reelle === null) {
            return null;
        }

        return [$this->entree_reelle, $this->sortie_reelle ?? $this->sortie_prevue];
    }

    /**
     * Bornes EFFECTIVES du séjour, telles que le socle les calcule.
     *
     * `CSejour::entree` et `sortie` sont des champs dérivés : la date réelle si elle existe, la date
     * prévue sinon. Elles valent pour tout séjour, y compris planifié — contrairement à
     * `stayBounds()`, qui ne rend les bornes que d'un séjour admis, parce qu'il sert à attribuer un
     * lit et qu'on n'attribue pas de lit avant l'admission.
     *
     * @return array{0: string, 1: string}
     */
    public function effectiveBounds(): array
    {
        return [
            $this->entree_reelle ?? $this->entree_prevue,
            $this->sortie_reelle ?? $this->sortie_prevue,
        ];
    }

    /**
     * Le séjour occupe-t-il un lit à l'instant donné ?
     *
     * Sert à mesurer l'occupation instantanée sans passer par la base.
     */
    public function occupiesBedAt(string $moment): bool
    {
        if (!$this->needs_bed) {
            return false;
        }

        $bounds = $this->stayBounds();
        if ($bounds === null) {
            return false;
        }

        return strcmp($moment, $bounds[0]) >= 0 && strcmp($moment, $bounds[1]) < 0;
    }
}
