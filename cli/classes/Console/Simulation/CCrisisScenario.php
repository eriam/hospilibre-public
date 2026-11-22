<?php

/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Cli\Console\Simulation;

/**
 * Scénario de crise déclaratif (crisis/<nom>.php). Tout est fonction du JOUR RELATIF au début
 * de la crise : advance réévalue l'état voulu chaque jour, sans mémoire, donc un rattrapage qui
 * retraverse des jours de crise produit le même effet qu'un passage unique.
 *
 * Format :
 *   multipliers : jour => [clé de débit => facteur], interpolation linéaire entre points-clés ;
 *                 une clé absente d'un point vaut 1 à ce point ; hors courbe = nominal.
 *   admission   : type des arrivées de crise, services cibles, intervention immédiate ou non.
 *   fenetres    : [de, a] avec effets (deprogrammer_operations, deprogrammer_consultations,
 *                 refus_admissions) ou capacité (renfort_lits, fermer_service).
 */
class CCrisisScenario
{
    public const NAMES = ['epidemie', 'plan_blanc', 'crise_interne'];

    private function __construct(private array $data) {}

    public static function fromName(string $name): self
    {
        if (!preg_match('/^[a-z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException("Nom de crise invalide : « $name »");
        }
        $path = __DIR__ . "/crisis/$name.php";
        if (!is_file($path)) {
            throw new \InvalidArgumentException(
                "Crise inconnue : « $name » (connues : " . implode(', ', self::NAMES) . ')'
            );
        }

        return self::fromArray(require $path);
    }

    public static function fromArray(array $data): self
    {
        $mult = (array) ($data['multipliers'] ?? []);
        ksort($mult);
        $data['multipliers'] = $mult;

        return new self($data);
    }

    public function getName(): string
    {
        return (string) ($this->data['name'] ?? 'crise');
    }

    /** @return array<string,float> facteurs du jour ; [] hors de la courbe (= nominal) */
    public function factorsAt(int $rel): array
    {
        $points = $this->data['multipliers'];
        if ($points === [] || $rel < 0 || $rel > array_key_last($points)) {
            return [];
        }
        $keys = [];
        foreach ($points as $p) {
            $keys += array_flip(array_keys($p));
        }
        $days = array_keys($points);
        $prev = $days[0];
        $next = end($days);
        foreach ($days as $d) {
            if ($d <= $rel) {
                $prev = $d;
            }
            if ($d >= $rel) {
                $next = $d;
                break;
            }
        }
        $factors = [];
        foreach (array_keys($keys) as $key) {
            $a = (float) ($points[$prev][$key] ?? 1.0);
            $b = (float) ($points[$next][$key] ?? 1.0);
            $factors[$key] = $next === $prev ? $a : $a + ($b - $a) * ($rel - $prev) / ($next - $prev);
        }

        return $factors;
    }

    /** @return array{deprogrammer_operations:float, deprogrammer_consultations:float, refus_admissions:float} */
    public function effectsAt(int $rel): array
    {
        $effects = ['deprogrammer_operations' => 0.0, 'deprogrammer_consultations' => 0.0, 'refus_admissions' => 0.0];
        foreach ((array) ($this->data['fenetres'] ?? []) as $w) {
            if ($rel < (int) $w['de'] || $rel > (int) $w['a']) {
                continue;
            }
            foreach (array_keys($effects) as $k) {
                if (isset($w[$k])) {
                    $effects[$k] = max($effects[$k], (float) $w[$k]);
                }
            }
        }

        return $effects;
    }

    /** Fenêtres qui modifient la capacité en lits (renfort / fermeture d'aile). */
    public function getCapacityWindows(): array
    {
        return array_values(array_filter(
            (array) ($this->data['fenetres'] ?? []),
            static fn (array $w) => isset($w['renfort_lits']) || isset($w['fermer_service'])
        ));
    }

    /** Admissions de crise. provenance/mode_entree : codes PMSI de CSejour (5 = passage aux
     *  urgences, 8 = domicile). Le TYPE reste 'comp' : CAffectation::check() refuse de placer un
     *  séjour 'urg' hors d'un service d'urgence/UHCD, l'afflux se lit donc dans la provenance.
     *
     * @return array{type:string, provenance:?string, mode_entree:?string, services_cibles:string[], intervention:bool}
     */
    public function getAdmission(): array
    {
        $a = (array) ($this->data['admission'] ?? []);

        return [
            'type'            => (string) ($a['type'] ?? 'comp'),
            'provenance'      => isset($a['provenance']) ? (string) $a['provenance'] : null,
            'mode_entree'     => isset($a['mode_entree']) ? (string) $a['mode_entree'] : null,
            'services_cibles' => array_values((array) ($a['services_cibles'] ?? [])),
            'intervention'    => (bool) ($a['intervention'] ?? false),
        ];
    }

    /** Dernier jour où le scénario agit encore (point-clé ou fin de fenêtre). */
    public function getDuration(): int
    {
        $last = $this->data['multipliers'] === [] ? 0 : (int) array_key_last($this->data['multipliers']);
        foreach ((array) ($this->data['fenetres'] ?? []) as $w) {
            $last = max($last, (int) $w['a']);
        }

        return $last;
    }

    public function isActiveAt(int $rel): bool
    {
        return $rel >= 0 && $rel <= $this->getDuration();
    }

    /**
     * @param string[] $serviceNames services de l'hôpital
     *
     * @return string[] services cités par le scénario et absents de l'hôpital
     */
    public function missingServices(array $serviceNames): array
    {
        $cited = $this->getAdmission()['services_cibles'];
        foreach ($this->getCapacityWindows() as $w) {
            $cited[] = $w['renfort_lits']['service'] ?? $w['fermer_service'];
        }

        return array_values(array_diff(array_unique($cited), $serviceNames));
    }
}
