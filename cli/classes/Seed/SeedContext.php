<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

use Ox\Cli\Seed\Decor\SeedDecor;
use Ox\Core\CMbDT;
use Ox\Core\CStoredObject;

/**
 * Ce que tout builder du seed a besoin de connaître : l'échelle, le hasard, le chemin
 * d'écriture, l'instant de référence, et les objets déjà étiquetés.
 *
 * L'INSTANT DE RÉFÉRENCE est injectable, et ce n'est pas un détail de confort. La fenêtre
 * temporelle du jeu de données enjambe « maintenant » (c'est ce qui rend l'application vivante
 * plutôt que peuplée d'archives), donc les builders doivent lire une date courante. Si chacun
 * appelait l'horloge, deux exécutions à graine égale à deux dates différentes produiraient des
 * dates différentes, et les tests d'invariants temporels dépendraient du jour où ils tournent.
 * Un seul instant, fixé à l'entrée, rend le jeu de données reproductible ET testable.
 *
 * Les FLUX DE HASARD sont nommés : `stream('patients')` rend toujours le même sous-générateur.
 * Ajouter un tirage aux patients ne décale donc pas les séjours, et l'établissement ne change
 * pas entièrement d'identité à chaque évolution du code.
 */
final class SeedContext
{
    /** @var SeedProfile */
    private $profile;

    /** @var SeedRandom */
    private $random;

    /** @var SeedWriter */
    private $writer;

    /** @var string Instant de référence, au format 'Y-m-d H:i:s' */
    private $now;

    /** @var SeedRandom[] Sous-générateurs par étiquette */
    private $streams = [];

    /** @var CStoredObject[] Objets étiquetés, par tag */
    private $tagged = [];

    /** @var IppRegistry|null Attribution des IPP, absente quand l'opérateur l'a désactivée */
    private $ipp;

    /** @var AnchoredCohort Patients dont l'identité est imposée ; vide par défaut */
    private $anchors;

    /**
     * @param string|null       $now     Instant de référence ('Y-m-d H:i:s'). Par défaut, l'heure courante.
     * @param IppRegistry|null  $ipp     Attribution des IPP, ou null pour ne pas en poser
     * @param AnchoredCohort|null $anchors Identités imposées, ou null pour n'en imposer aucune
     *
     * @throws SeedException
     */
    public function __construct(
        SeedProfile $profile,
        SeedRandom $random,
        SeedWriter $writer,
        ?string $now = null,
        ?IppRegistry $ipp = null,
        ?AnchoredCohort $anchors = null
    ) {
        $now = $now ?? CMbDT::dateTime();

        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)$now)) {
            throw new SeedException("Instant de référence invalide : '{$now}' (attendu 'Y-m-d H:i:s')");
        }

        $this->profile = $profile;
        $this->random  = $random;
        $this->writer  = $writer;
        $this->now     = $now;
        $this->ipp     = $ipp;
        $this->anchors = $anchors ?? AnchoredCohort::none();
    }

    /**
     * L'attribution des IPP, ou null quand l'opérateur a demandé de s'en passer.
     *
     * Rendue nullable plutôt que remplacée par un objet inerte : un builder qui pose un IPP doit
     * pouvoir constater qu'il n'y en aura pas, et un registre factice le lui cacherait.
     */
    public function ipp(): ?IppRegistry
    {
        return $this->ipp;
    }

    /** Les identités imposées. Vide, et non null, quand il n'y en a pas. */
    public function anchors(): AnchoredCohort
    {
        return $this->anchors;
    }

    public function profile(): SeedProfile
    {
        return $this->profile;
    }

    /**
     * Le décor : ce qui fait que ce jeu de données ressemble à cet établissement-là.
     *
     * Il vient DU PROFIL et n'est pas injecté ici séparément : le profil en dépend pour son
     * arithmétique, et deux décors — l'un porté par le profil, l'autre par le contexte — finiraient par
     * diverger hors de toute détection.
     */
    public function decor(): SeedDecor
    {
        return $this->profile->decor();
    }

    public function writer(): SeedWriter
    {
        return $this->writer;
    }

    /** Instant de référence du jeu de données. */
    public function now(): string
    {
        return $this->now;
    }

    /** Jour de référence, sans l'heure. */
    public function today(): string
    {
        return substr($this->now, 0, 10);
    }

    /**
     * Décale l'instant de référence d'une expression relative ('-3 months', '+2 days'…).
     *
     * Passe par CMbDT pour rester cohérent avec le reste du socle, et prend TOUJOURS l'instant
     * de référence comme origine — jamais l'heure courante.
     */
    public function shift(string $expression): string
    {
        return CMbDT::dateTime($expression, $this->now);
    }

    /** Idem, en date seule. */
    public function shiftDate(string $expression): string
    {
        return CMbDT::date($expression, $this->now);
    }

    /** Début de la fenêtre temporelle du profil. */
    public function windowStart(): string
    {
        return $this->shift('-' . $this->profile->windowMonths() . ' months');
    }

    /**
     * Sous-générateur nommé. Le même nom rend toujours le même générateur, dans l'état où les
     * appels précédents l'ont laissé.
     */
    public function stream(string $label): SeedRandom
    {
        if (!isset($this->streams[$label])) {
            $this->streams[$label] = $this->random->derive($label);
        }

        return $this->streams[$label];
    }

    /**
     * Écrit un objet et retient son tag, pour que les builders suivants puissent le retrouver.
     *
     * @throws SeedException
     */
    public function write(CStoredObject $object, ?string $tag = null): CStoredObject
    {
        $this->writer->write($object, $tag);

        if ($tag !== null) {
            $this->tagged[$tag] = $object;
        }

        return $object;
    }

    /**
     * @throws SeedException
     */
    public function tagged(string $tag): CStoredObject
    {
        if (!isset($this->tagged[$tag])) {
            throw new SeedException("Aucun objet étiqueté '{$tag}'");
        }

        return $this->tagged[$tag];
    }

    public function hasTag(string $tag): bool
    {
        return isset($this->tagged[$tag]);
    }

    /**
     * @return string[]
     */
    public function tags(): array
    {
        return array_keys($this->tagged);
    }
}
