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
 * D'où viennent les identités des personnes du jeu de données.
 *
 * Deux exigences, et elles se tiennent : les listes doivent être ASSEZ LARGES pour que les homonymies
 * parfaites restent rares, et ENTIÈREMENT LOCALES pour que le seed reste hors ligne et rejouable. Une
 * source qui interrogerait un service au moment du tirage casserait la reproductibilité, qui est la
 * propriété sur laquelle tout le reste repose.
 */
interface IdentitySource
{
    /** Ce qui sera rapporté à l'opérateur : d'où viennent ces identités, et combien il y en a. */
    public function describe(): string;

    /**
     * @return string[]
     * @throws SeedException
     */
    public function surnames(): array;

    /**
     * Poids des patronymes, pour un tirage proportionnel à leur fréquence réelle.
     *
     * @return array<string, int> patronyme => nombre de porteurs
     */
    public function surnameWeights(): array;

    /**
     * @return string[]
     * @throws SeedException
     */
    public function firstNames(string $sex): array;

    /**
     * Poids des prénoms, pour un tirage proportionnel à leur fréquence réelle.
     *
     * TABLEAU VIDE QUAND LA SOURCE N'EN PORTE PAS, et l'appelant retombe alors sur un tirage
     * uniforme. C'est ce qui permet à une source d'identités sans fréquences — un fichier imposé par
     * un exploitant, les listes embarquées — de rester employable.
     *
     * @return array<string, int> prénom => nombre d'occurrences
     */
    public function firstNameWeights(string $sex): array;

    /**
     * Communes de repli, utilisées seulement quand le décor n'a pas de géographie.
     *
     * @return string[]
     * @throws SeedException
     */
    public function towns(): array;
}
