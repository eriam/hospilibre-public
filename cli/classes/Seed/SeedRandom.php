<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

/**
 * Générateur pseudo-aléatoire explicite du seed — xorshift32, graine 32 bits.
 *
 * POURQUOI NE PAS UTILISER mt_rand() / shuffle() / array_rand() : leur séquence n'est
 * garantie stable ni entre versions de PHP, ni entre plateformes. Or la promesse du seed est
 * « même profil + même graine ⇒ mêmes données », y compris sur une autre machine : une
 * consigne d'exercice peut citer un patient ou un lit précis. Un générateur écrit dans le
 * dépôt est la seule façon de rendre la reproductibilité indépendante de l'environnement.
 * Un test de garde (NoNonDeterministicRandomnessTest) vérifie qu'aucune primitive d'aléa du
 * langage n'est appelée dans ce répertoire.
 *
 * Tout passe par des entiers : aucun tirage ne dépend d'une opération flottante, dont
 * l'arrondi pourrait varier. `float()` existe pour l'affichage et les seuils de confort, pas
 * comme support des tirages.
 */
final class SeedRandom
{
    /**
     * Graine par défaut, documentée et figée : la date de rédaction du design du seed.
     * La changer change TOUTES les données produites sans option explicite — c'est donc une
     * décision, pas un détail.
     */
    public const DEFAULT_SEED = 20260925;

    /** Graine de repli quand la graine demandée vaut 0 : 0 est un point fixe de xorshift. */
    private const NON_ZERO_FALLBACK = 0x9E3779B9;

    private const MASK32 = 0xFFFFFFFF;
    private const RANGE  = 0x100000000;

    /** @var int Graine effective, telle qu'elle doit être rapportée à l'opérateur */
    private $seed;

    /** @var int État courant, sur 32 bits */
    private $state;

    public function __construct(int $seed = self::DEFAULT_SEED)
    {
        $seed = $seed & self::MASK32;

        $this->seed  = $seed;
        $this->state = $seed === 0 ? self::NON_ZERO_FALLBACK : $seed;
    }

    /**
     * Graine effective de ce générateur, à journaliser avec les données produites : sans
     * elle, un jeu de données n'est pas reproductible.
     */
    public function seed(): int
    {
        return $this->seed;
    }

    /**
     * Sous-générateur nommé, dérivé de cette graine.
     *
     * Sert à isoler les flux de tirage : si les patients et les séjours puisent dans le même
     * flux, ajouter un tirage aux patients décale tout ce qui suit et l'établissement change
     * entièrement d'une version du code à l'autre. Avec un sous-générateur par famille
     * d'objets, une évolution locale ne perturbe que sa famille.
     *
     * Le mélange de l'étiquette utilise FNV-1a 32 bits écrit ici même : les fonctions de
     * hachage de PHP (`hash()`, `crc32()`) sont stables, mais autant ne rien devoir à
     * l'environnement sur un chemin qui décide de l'identité des données.
     */
    public function derive(string $label): self
    {
        $hash = 0x811C9DC5; // FNV-1a offset basis

        $length = strlen($label);
        for ($i = 0; $i < $length; $i++) {
            $hash ^= ord($label[$i]);
            // FNV prime 16777619, en restant sur 32 bits
            $hash = ($hash * 16777619) & self::MASK32;
        }

        return new self(($this->seed ^ $hash) & self::MASK32);
    }

    /**
     * Prochain entier brut sur 32 bits (0 .. 2^32-1).
     */
    public function nextInt(): int
    {
        $x = $this->state;

        $x ^= ($x << 13) & self::MASK32;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & self::MASK32;

        return $this->state = $x & self::MASK32;
    }

    /**
     * Entier dans [$min, $max], bornes comprises.
     *
     * Tirage par rejet plutôt que simple modulo : le modulo favorise les premières valeurs de
     * l'intervalle dès que l'étendue ne divise pas 2^32. Sur des dizaines de milliers de
     * séjours ce biais devient visible dans les statistiques que le seed est justement censé
     * rendre plausibles.
     *
     * @throws SeedException si l'intervalle est vide
     */
    public function int(int $min, int $max): int
    {
        if ($min > $max) {
            throw new SeedException("Intervalle vide : [{$min}, {$max}]");
        }

        if ($min === $max) {
            return $min;
        }

        $span = $max - $min + 1;

        // Plus grand multiple de l'étendue tenant dans 2^32 : au-delà, on rejette.
        $limit = intdiv(self::RANGE, $span) * $span;

        do {
            $draw = $this->nextInt();
        } while ($draw >= $limit);

        return $min + ($draw % $span);
    }

    /**
     * Flottant dans [0, 1). Pour l'affichage et les seuils de confort — pas pour les tirages,
     * qui passent tous par int().
     */
    public function float(): float
    {
        return $this->nextInt() / self::RANGE;
    }

    /**
     * Tire vrai avec une probabilité exprimée en pour mille, pour rester entier.
     *
     * @throws SeedException
     */
    public function chanceInThousand(int $per_thousand): bool
    {
        if ($per_thousand < 0 || $per_thousand > 1000) {
            throw new SeedException("Probabilité hors bornes : {$per_thousand} pour mille");
        }

        if ($per_thousand === 0) {
            return false;
        }

        return $this->int(1, 1000) <= $per_thousand;
    }

    /**
     * Un élément au hasard. Les clés sont ignorées : c'est le contenu qui est tiré.
     *
     * @param array $values
     *
     * @return mixed
     * @throws SeedException
     */
    public function pick(array $values)
    {
        if ($values === []) {
            throw new SeedException('Tirage dans une collection vide');
        }

        $values = array_values($values);

        return $values[$this->int(0, count($values) - 1)];
    }

    /**
     * Tire une clé selon des poids entiers ({'ambu' => 40, 'comp' => 35, ...}).
     *
     * Poids entiers exigés : des poids flottants feraient dépendre le résultat d'une somme de
     * flottants, donc de l'ordre d'accumulation. Les proportions du seed sont de toute façon
     * exprimées en pourcentages.
     *
     * @param int[] $weights
     *
     * @return string|int la clé tirée
     * @throws SeedException
     */
    public function pickWeighted(array $weights)
    {
        $total = 0;
        foreach ($weights as $key => $weight) {
            if (!is_int($weight) || $weight < 0) {
                throw new SeedException("Poids non entier ou négatif pour '{$key}'");
            }
            $total += $weight;
        }

        if ($total <= 0) {
            throw new SeedException('Somme des poids nulle');
        }

        $draw = $this->int(1, $total);

        $cumulative = 0;
        foreach ($weights as $key => $weight) {
            $cumulative += $weight;
            if ($draw <= $cumulative) {
                return $key;
            }
        }

        // Inatteignable : $draw <= $total == $cumulative final.
        throw new SeedException('Tirage pondéré incohérent');
    }

    /**
     * Mélange de Fisher-Yates. Rend un nouveau tableau réindexé, n'altère pas l'entrée.
     *
     * @param array $values
     *
     * @return array
     * @throws SeedException
     */
    public function shuffle(array $values): array
    {
        $values = array_values($values);

        for ($i = count($values) - 1; $i > 0; $i--) {
            $j = $this->int(0, $i);

            $swap       = $values[$i];
            $values[$i] = $values[$j];
            $values[$j] = $swap;
        }

        return $values;
    }

    /**
     * $count éléments distincts, dans un ordre tiré.
     *
     * @param array $values
     *
     * @return array
     * @throws SeedException
     */
    public function take(array $values, int $count): array
    {
        if ($count < 0) {
            throw new SeedException("Nombre d'éléments négatif : {$count}");
        }

        if ($count > count($values)) {
            throw new SeedException(
                'Impossible de tirer ' . $count . ' éléments distincts parmi ' . count($values)
            );
        }

        return array_slice($this->shuffle($values), 0, $count);
    }
}
