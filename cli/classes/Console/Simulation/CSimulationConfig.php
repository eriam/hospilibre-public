<?php

/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Cli\Console\Simulation;

class CSimulationConfig
{
    /** Clés de débit que tout profil typé doit définir (une crise multiplie ces valeurs :
     *  une clé absente resterait au défaut codé dans advance et ignorerait la crise). */
    public const RATE_KEYS = [
        'arrivals_per_day', 'avg_sejour_days', 'transfer_rate', 'consults_per_day',
        'operations_per_day', 'constantes_per_day', 'ambulatoire_rate',
    ];

    /** @param array{name?:string,hospital?:array,sizes?:array,rates?:array} $data */
    private function __construct(private array $data) {}

    public static function fromFile(string $path): self
    {
        $data = require $path;
        return new self(is_array($data) ? $data : []);
    }

    /** Fichier d'un profil nommé (`moyen`/`on` = hopital_moyen.php, sinon <profil>.php). */
    public static function profilePath(string $profile): string
    {
        $file = in_array($profile, ['moyen', 'on'], true) ? 'hopital_moyen' : $profile;
        if (!preg_match('/^[a-z0-9_]+$/', $file)) {
            throw new \InvalidArgumentException("Profil de simulation invalide : « $profile »");
        }
        $path = __DIR__ . "/config/$file.php";
        if (!is_file($path)) {
            throw new \InvalidArgumentException("Profil de simulation inconnu : « $profile »");
        }

        return $path;
    }

    public static function fromProfile(string $profile): self
    {
        return self::fromFile(self::profilePath($profile));
    }

    /** Config d'advance : --config explicite, sinon le profil enregistré au semis, sinon « moyen ». */
    public static function resolveForAdvance(?string $configOption, ?string $stateConfigName): self
    {
        if ($configOption) {
            return self::fromFile($configOption);
        }

        return self::fromProfile($stateConfigName ?: 'moyen');
    }

    /** Profil typé = services nommés décrits sous `structure.services`. */
    public function isTyped(): bool
    {
        return !empty($this->data['structure']['services']);
    }

    /**
     * Services nommés du profil typé (vide pour un profil non typé : le bootstrap
     * garde alors son comportement générique « Service 1…N »).
     *
     * @return array<int, array{nom:string, code:string, chambres:int, lits_par_chambre:int,
     *                          spec_cpam:int[], medecins:int, personnels:int, poids_admissions:float}>
     */
    public function getServices(): array
    {
        if (!$this->isTyped()) {
            return [];
        }

        return array_map(static fn (array $s): array => [
            'nom'              => (string) $s['nom'],
            'code'             => (string) ($s['code'] ?? ''),
            'chambres'         => (int) ($s['chambres'] ?? 8),
            'lits_par_chambre' => (int) ($s['lits_par_chambre'] ?? 2),
            'spec_cpam'        => array_map('intval', (array) ($s['spec_cpam'] ?? [])),
            'medecins'         => (int) ($s['medecins'] ?? 0),
            'personnels'       => (int) ($s['personnels'] ?? 0),
            'poids_admissions' => (float) ($s['poids_admissions'] ?? 1.0),
        ], array_values($this->data['structure']['services']));
    }

    public function getBlocs(): int
    {
        return $this->isTyped()
            ? (int) ($this->data['structure']['blocs'] ?? 0)
            : $this->getInt('salles_bloc', 3);
    }

    public function getTotalLits(): int
    {
        if (!$this->isTyped()) {
            return $this->getInt('services', 4) * $this->getInt('chambres_par_service', 8)
                * $this->getInt('lits_par_chambre', 2);
        }

        return array_sum(array_map(
            static fn (array $s): int => $s['chambres'] * $s['lits_par_chambre'],
            $this->getServices()
        ));
    }

    /** Nombre de séjours EN COURS posés au bootstrap : borné par les patients et les lits
     *  disponibles, pas par le nombre de praticiens (un praticien suit plusieurs séjours ;
     *  il en faut seulement au moins un). */
    public function initialSejours(int $patients, int $lits, int $praticiens): int
    {
        if ($praticiens <= 0) {
            return 0;
        }

        return max(0, min($this->getInt('sejours_en_cours', 15), $patients, $lits));
    }

    /** Type des séjours d'arrivée nominale (`comp` par défaut, `ssr` pour un SSR). */
    public function getSejourType(): string
    {
        return (string) ($this->data['sejour_type'] ?? 'comp');
    }

    /**
     * Copie dont les débits cités sont multipliés (crise). Une clé absente du profil
     * n'est pas créée : le facteur s'applique à une valeur définie, jamais au défaut.
     *
     * @param array<string,float> $factors
     */
    public function withRateFactors(array $factors): self
    {
        $data = $this->data;
        foreach ($factors as $key => $factor) {
            if (isset($data['rates'][$key])) {
                $data['rates'][$key] = $data['rates'][$key] * (float) $factor;
            }
        }

        return new self($data);
    }

    public function getName(): string
    {
        return (string) ($this->data['name'] ?? 'custom');
    }

    /** @return array<string,mixed> */
    public function getHospital(): array
    {
        return (array) ($this->data['hospital'] ?? []);
    }

    public function getInt(string $key, int $default): int
    {
        return (int) (($this->data['sizes'] ?? [])[$key] ?? $default);
    }

    public function getRateInt(string $key, int $default): int
    {
        return (int) round((float) (($this->data['rates'] ?? [])[$key] ?? $default));
    }

    public function getRateFloat(string $key, float $default): float
    {
        return (float) (($this->data['rates'] ?? [])[$key] ?? $default);
    }

    /**
     * Retourne une copie avec surcharges. Clés hôpital (nom, adresse, finess…)
     * appliquées à `hospital`, clés de taille (services, medecins…) à `sizes`.
     *
     * @param array<string,scalar|null> $opts
     */
    public function withOverrides(array $opts): self
    {
        $data = $this->data;
        foreach ($opts as $k => $v) {
            if ($v === null) {
                continue;
            }
            if (array_key_exists($k, $data['sizes'] ?? [])) {
                $data['sizes'][$k] = (int) $v;
            } else {
                $data['hospital'][$k] = $v;
            }
        }

        return new self($data);
    }
}
