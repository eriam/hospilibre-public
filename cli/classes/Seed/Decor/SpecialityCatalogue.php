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
 * Les unités de soins d'un décor, et ce que chacune implique.
 *
 * C'est la moitié VISIBLE d'un décor : ce catalogue décide si l'établissement est un centre
 * hospitalier généraliste, une clinique chirurgicale ou un centre pédiatrique. Il porte l'ordre
 * d'ouverture des unités — significatif, parce qu'un petit profil ne prend que les premières —, qui
 * opère et qui accueille les urgences, les actes de chaque spécialité chirurgicale, et les patients
 * que chaque unité admet.
 *
 * L'ORDRE EST UN CONTRAT : `first()` doit rendre les $count premières unités, et les premières
 * doivent composer à elles seules un établissement crédible. Un décor dont les quatre premières
 * unités seraient toutes chirurgicales ne permettrait pas d'illustrer une hospitalisation ordinaire.
 */
interface SpecialityCatalogue
{
    /** Nombre total d'unités que ce catalogue peut ouvrir. */
    public function count(): int;

    /**
     * Les $count premières unités, dans l'ordre d'ouverture.
     *
     * @return array<int, array{slug: string, service: string, fonction: string, surgical: bool, intensive: bool}>
     * @throws SeedException
     */
    public function first(int $count): array;

    /**
     * Composition des $count premières unités, pour dimensionner ce qui en dépend — le bloc, l'effectif
     * d'anesthésie, la répartition des lits.
     *
     * Les familles sont EXCLUSIVES : une unité est d'hospitalisation, de soins continus ou d'accueil
     * des urgences, jamais deux à la fois.
     *
     * @return array{surgical: int, ward: int, intensive: int, emergency: int, paediatric: int}
     * @throws SeedException
     */
    public function counts(int $count): array;

    /**
     * Actes de la spécialité, éventuellement restreints au sexe du patient opéré.
     *
     * @return string[]
     * @throws SeedException
     */
    public function procedures(string $slug, ?string $sex = null): array;

    /**
     * Patients que l'unité admet : tranche d'âge, et sexe quand il est imposé.
     *
     * @return array{min_age: int, max_age: int, sex: string|null}
     */
    public function patientProfile(string $slug): array;

    /** L'unité accueille-t-elle des mineurs ? */
    public function isPaediatric(string $slug): bool;

    /** L'unité peut-elle héberger un séjour urgent ? */
    public function isEmergency(string $slug): bool;

    /**
     * Drapeaux de `CService` à poser pour cette unité — ceux que `CAffectation::check()` exige pour
     * autoriser l'hébergement d'un séjour urgent.
     *
     * @return string[]
     */
    public function serviceFlags(string $slug): array;

    /** Nom de la fonction d'anesthésie, transversale aux unités. */
    public function anaesthesiaFunction(): string;
}
