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
 * Les décors disponibles, nommés.
 *
 * La liste est EXPLICITE et non découverte par balayage du disque : un décor est un choix d'exploitation
 * — ce qu'un formateur montrera à ses étudiants — et une découverte automatique ferait apparaître dans
 * l'aide de la commande le moindre brouillon déposé dans le dossier.
 */
final class DecorRegistry
{
    /** @var array<string, class-string<SeedDecor>> */
    private const DECORS = [
        FrenchHospitalDecor::NAME => FrenchHospitalDecor::class,
        SurgicalClinicDecor::NAME => SurgicalClinicDecor::class,
    ];

    public const DEFAULT_DECOR = FrenchHospitalDecor::NAME;

    /**
     * @return string[]
     */
    public static function names(): array
    {
        return array_keys(self::DECORS);
    }

    /**
     * @throws SeedException
     */
    public static function get(string $name): SeedDecor
    {
        if (!isset(self::DECORS[$name])) {
            throw new SeedException(
                "Décor inconnu '{$name}' (attendu : " . implode(', ', self::names()) . ')'
            );
        }

        $class = self::DECORS[$name];

        return new $class();
    }

    /**
     * Ce que la commande affiche pour aider à choisir.
     *
     * @return array<string, string> nom => description
     * @throws SeedException
     */
    public static function describe(): array
    {
        $described = [];

        foreach (self::names() as $name) {
            $described[$name] = self::get($name)->description();
        }

        return $described;
    }
}
