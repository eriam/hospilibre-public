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
 * Catalogue d'unités piloté par des DONNÉES, et non par du code.
 *
 * C'est l'implémentation qu'un décor utilise sauf raison contraire : il fournit ses tableaux, la
 * logique de recherche est ici. Écrire un décor ne demande donc pas d'implémenter neuf méthodes, mais
 * de décrire des unités — ce qui est le but d'un décor.
 *
 * Seules les EXCEPTIONS sont déclarées : un slug absent de `$patient_profiles` accueille des adultes
 * de tout âge, un slug absent de `$emergency_units` n'héberge pas d'urgence, un acte absent de
 * `$procedure_sex` concerne les deux sexes. Un décor n'a donc à décrire que ce qui sort de l'ordinaire.
 */
final class ArrayCatalogue implements SpecialityCatalogue
{
    /** @var array<int, array{slug: string, service: string, fonction: string, surgical: bool, intensive: bool}> */
    private $entries;

    /** @var array<string, string[]> Actes par spécialité chirurgicale */
    private $procedures;

    /** @var array<string, string> Sexe imposé par certains actes, par libellé */
    private $procedure_sex;

    /** @var array<string, array{min_age?: int, max_age?: int, sex?: string}> Exceptions d'admission */
    private $patient_profiles;

    /** @var array<string, string> Unités d'accueil des urgences => drapeau de CService */
    private $emergency_units;

    /** @var string[] Unités qui accueillent des mineurs */
    private $paediatric;

    /** @var string */
    private $anaesthesia_function;

    /** @var int[] [majorité, âge maximal] de la population adulte */
    private $adult_ages;

    /**
     * @param array<int, array{slug: string, service: string, fonction: string, surgical: bool, intensive: bool}> $entries
     * @param array<string, string[]>                                      $procedures
     * @param array<string, array{min_age?: int, max_age?: int, sex?: string}> $patient_profiles
     * @param array<string, string>                                        $emergency_units
     * @param string[]                                                     $paediatric
     * @param array<string, string>                                        $procedure_sex
     * @param int[]                                                        $adult_ages
     *
     * @throws SeedException
     */
    public function __construct(
        array $entries,
        array $procedures = [],
        array $patient_profiles = [],
        array $emergency_units = [],
        array $paediatric = [],
        array $procedure_sex = [],
        string $anaesthesia_function = 'Anesthésie',
        array $adult_ages = [18, 95]
    ) {
        if ($entries === []) {
            throw new SeedException('Catalogue vide : un décor doit ouvrir au moins une unité');
        }

        $this->entries              = array_values($entries);
        $this->procedures           = $procedures;
        $this->patient_profiles     = $patient_profiles;
        $this->emergency_units      = $emergency_units;
        $this->paediatric           = $paediatric;
        $this->procedure_sex        = $procedure_sex;
        $this->anaesthesia_function = $anaesthesia_function;
        $this->adult_ages           = $adult_ages;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * @inheritDoc
     */
    public function first(int $count): array
    {
        if ($count < 1) {
            throw new SeedException("Nombre d'unités invalide : {$count}");
        }

        if ($count > $this->count()) {
            throw new SeedException(
                'Ce catalogue ne contient que ' . $this->count() . " unités, {$count} demandées."
                . ' Ajouter des entrées au décor plutôt que de dupliquer des services.'
            );
        }

        return array_slice($this->entries, 0, $count);
    }

    /**
     * @inheritDoc
     */
    public function counts(int $count): array
    {
        $counts = [
            'surgical'   => 0,
            'ward'       => 0,
            'intensive'  => 0,
            'emergency'  => 0,
            'paediatric' => 0,
        ];

        foreach ($this->first($count) as $spec) {
            if ($spec['surgical']) {
                $counts['surgical']++;
            }

            // Trois familles d'unités, exclusives : hospitalisation, soins continus, accueil des
            // urgences. Chacune a sa bande d'occupation et ses durées de séjour, donc chacune doit
            // être comptée à part — un compte global ne correspondrait à aucune des trois.
            if ($this->isEmergency($spec['slug'])) {
                $counts['emergency']++;
            } elseif ($spec['intensive']) {
                $counts['intensive']++;
            } else {
                $counts['ward']++;
            }

            if ($this->isPaediatric($spec['slug'])) {
                $counts['paediatric']++;
            }
        }

        return $counts;
    }

    /**
     * @inheritDoc
     */
    public function procedures(string $slug, ?string $sex = null): array
    {
        if (!isset($this->procedures[$slug])) {
            throw new SeedException(
                "Aucun acte défini pour la spécialité '{$slug}' : une spécialité chirurgicale doit"
                . ' avoir ses propres actes, sinon le programme du bloc devient incohérent.'
            );
        }

        $procedures = $this->procedures[$slug];

        if ($sex === null) {
            return $procedures;
        }

        $eligible = [];
        foreach ($procedures as $procedure) {
            if (($this->procedure_sex[$procedure] ?? $sex) === $sex) {
                $eligible[] = $procedure;
            }
        }

        // Une spécialité dont TOUS les actes seraient réservés à l'autre sexe rendrait la liste
        // complète plutôt que rien : mieux vaut un acte discutable qu'une intervention sans libellé.
        return $eligible === [] ? $procedures : $eligible;
    }

    /**
     * @inheritDoc
     */
    public function patientProfile(string $slug): array
    {
        $profile = $this->patient_profiles[$slug] ?? [];

        return [
            'min_age' => $profile['min_age'] ?? $this->adult_ages[0],
            'max_age' => $profile['max_age'] ?? $this->adult_ages[1],
            'sex'     => $profile['sex'] ?? null,
        ];
    }

    public function isPaediatric(string $slug): bool
    {
        return in_array($slug, $this->paediatric, true);
    }

    public function isEmergency(string $slug): bool
    {
        return isset($this->emergency_units[$slug]);
    }

    /**
     * @inheritDoc
     */
    public function serviceFlags(string $slug): array
    {
        return isset($this->emergency_units[$slug]) ? [$this->emergency_units[$slug]] : [];
    }

    public function anaesthesiaFunction(): string
    {
        return $this->anaesthesia_function;
    }
}
