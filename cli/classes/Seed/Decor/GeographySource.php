<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed\Decor;

use Ox\Cli\Seed\SeedException;
use Ox\Cli\Seed\SeedRandom;

/**
 * Où l'établissement est implanté, et d'où viennent ses patients.
 *
 * C'est la source de la COHÉRENCE TERRITORIALE : un hôpital recrute autour de lui, et c'est la première
 * chose qu'un tableau de provenance montre. Une géographie doit donc savoir répondre à deux questions
 * liées — où poser l'établissement, et qui habite à proximité — la seconde dépendant de la première.
 *
 * Un décor peut ne pas en avoir : les patients reçoivent alors une adresse fictive, sans territoire.
 */
interface GeographySource
{
    /** Ce qui sera rapporté à l'opérateur : quelle source, et combien de lieux. */
    public function describe(): string;

    public function isAvailable(): bool;

    /**
     * Lieu d'implantation de l'établissement.
     *
     * @return array<string, string>|null les clés dépendent de la source, `commune` et `code_postal`
     *                                    étant les seules dont le moteur se sert
     * @throws SeedException
     */
    public function pickHospitalTown(SeedRandom $random): ?array;

    /**
     * Lieu de résidence d'un patient, cohérent avec l'implantation.
     *
     * @param array<string, string> $hospital
     *
     * @return array<string, string>|null
     * @throws SeedException
     */
    public function pickPatientTown(SeedRandom $random, array $hospital): ?array;
}
