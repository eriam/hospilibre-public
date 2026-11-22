<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

/**
 * Allocateur de lits : garantit qu'un lit n'est jamais occupé par deux patients à la fois.
 *
 * C'est le cœur de la plausibilité du jeu de données, et la raison pour laquelle les anciens
 * générateurs `populate` ne pouvaient pas produire de données cohérentes : ils tiraient
 * service et lit indépendamment, sans mémoire de ce qui était déjà occupé.
 *
 * Les intervalles sont traités comme SEMI-OUVERTS [entrée, sortie[ : deux affectations
 * adjacentes (la sortie de l'une égale l'entrée de l'autre) sont acceptées, ce qui correspond
 * à la réalité d'un lit libéré le matin et réoccupé le même jour.
 *
 * Les bornes sont des chaînes de date SQL ('Y-m-d' ou 'Y-m-d H:i[:s]'), comparées
 * lexicographiquement : ce format est trié par ordre chronologique, ce qui évite toute
 * conversion — donc tout écart de fuseau ou d'arrondi — sur un chemin qui décide d'un
 * invariant.
 */
final class BedAllocator
{
    /** Format accepté pour les bornes : date SQL, heure et secondes facultatives. */
    private const BOUND_PATTERN = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/';

    /**
     * @var array<string, array<int, string[]>> identifiant de lit => liste [entrée, sortie]
     */
    private $occupied = [];

    /**
     * Le lit est-il libre sur tout l'intervalle demandé ?
     *
     * @throws SeedException
     */
    public function isFree(string $bed, string $from, string $to): bool
    {
        $this->assertInterval($from, $to);

        foreach ($this->occupied[$bed] ?? [] as $interval) {
            // Chevauchement en semi-ouvert : from < borne_haute ET to > borne_basse.
            if (strcmp($from, $interval[1]) < 0 && strcmp($to, $interval[0]) > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Réserve l'intervalle. Rend false — sans rien modifier — si le lit est déjà pris.
     *
     * Ne lève pas sur conflit : un conflit est un cas NORMAL pour l'appelant, qui doit alors
     * essayer un autre lit. Seule une borne invalide est une erreur de programmation.
     *
     * @throws SeedException
     */
    public function allocate(string $bed, string $from, string $to): bool
    {
        if (!$this->isFree($bed, $from, $to)) {
            return false;
        }

        $this->occupied[$bed][] = [$from, $to];

        return true;
    }

    /**
     * Libère un intervalle exactement réservé auparavant. Rend false s'il n'existait pas.
     *
     * @throws SeedException
     */
    public function release(string $bed, string $from, string $to): bool
    {
        $this->assertInterval($from, $to);

        foreach ($this->occupied[$bed] ?? [] as $index => $interval) {
            if ($interval[0] === $from && $interval[1] === $to) {
                unset($this->occupied[$bed][$index]);

                if ($this->occupied[$bed] === []) {
                    unset($this->occupied[$bed]);
                } else {
                    $this->occupied[$bed] = array_values($this->occupied[$bed]);
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Premier lit libre de la liste proposée, ou null si aucun.
     *
     * L'ordre de la liste fait foi : c'est à l'appelant de la mélanger avec son SeedRandom s'il
     * veut une répartition tirée, pour que l'aléa reste concentré dans le générateur.
     *
     * @param string[] $beds
     *
     * @throws SeedException
     */
    public function firstFree(array $beds, string $from, string $to): ?string
    {
        foreach ($beds as $bed) {
            if ($this->isFree($bed, $from, $to)) {
                return $bed;
            }
        }

        return null;
    }

    /**
     * Intervalles réservés d'un lit, dans l'ordre de réservation.
     *
     * @return array<int, string[]>
     */
    public function intervals(string $bed): array
    {
        return $this->occupied[$bed] ?? [];
    }

    /**
     * Nombre de lits occupés à un instant donné, parmi ceux connus de l'allocateur.
     *
     * Sert à vérifier la bande d'occupation visée par le profil : c'est la mesure que regarde
     * un formateur sur un tableau d'occupation.
     *
     * @param string[]|null $keys Périmètre de mesure, ou null pour tous les lits connus. Les
     *                            unités de soins continus ont leur propre bande d'occupation :
     *                            mesurer les deux ensemble donnerait un taux qui ne correspond à
     *                            aucune des deux.
     *
     * @throws SeedException
     */
    public function occupiedAt(string $moment, ?array $keys = null): int
    {
        $this->assertBound($moment);

        $scope = $keys === null
            ? $this->occupied
            : array_intersect_key($this->occupied, array_flip($keys));

        $count = 0;
        foreach ($scope as $intervals) {
            foreach ($intervals as $interval) {
                if (strcmp($moment, $interval[0]) >= 0 && strcmp($moment, $interval[1]) < 0) {
                    $count++;
                    break;
                }
            }
        }

        return $count;
    }

    /** Nombre de lits ayant au moins une réservation. */
    public function allocatedBeds(): int
    {
        return count($this->occupied);
    }

    /** Nombre total d'intervalles réservés, tous lits confondus. */
    public function totalIntervals(): int
    {
        $total = 0;
        foreach ($this->occupied as $intervals) {
            $total += count($intervals);
        }

        return $total;
    }

    public function reset(): void
    {
        $this->occupied = [];
    }

    /**
     * @throws SeedException
     */
    private function assertInterval(string $from, string $to): void
    {
        $this->assertBound($from);
        $this->assertBound($to);

        if (strcmp($from, $to) >= 0) {
            throw new SeedException("Intervalle vide ou inversé : '{$from}' -> '{$to}'");
        }
    }

    /**
     * @throws SeedException
     */
    private function assertBound(string $bound): void
    {
        if (!preg_match(self::BOUND_PATTERN, $bound)) {
            throw new SeedException(
                "Borne '{$bound}' : date SQL attendue (Y-m-d ou Y-m-d H:i[:s]). "
                . 'La comparaison est lexicographique, un autre format casserait '
                . "silencieusement l'invariant de non-chevauchement."
            );
        }
    }
}
