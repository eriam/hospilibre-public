<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed\Decor;

use Ox\Cli\Seed\FictitiousNames;

/**
 * Les identités du dépôt : un fichier CSV versionné, avec les listes embarquées en repli.
 *
 * `FictitiousNames` porte les données et la lecture du fichier ; cette classe n'est que la façade qui
 * les expose comme une source parmi d'autres. Un décor qui voudrait des identités allemandes ou des
 * identités tirées d'un autre fichier écrit la sienne sans toucher à celle-ci.
 */
final class FileIdentities implements IdentitySource
{
    public function describe(): string
    {
        return FictitiousNames::source();
    }

    /**
     * @inheritDoc
     */
    public function surnames(): array
    {
        return FictitiousNames::surnames();
    }

    public function surnameWeights(): array
    {
        return FictitiousNames::surnameWeights();
    }

    /**
     * @inheritDoc
     */
    public function firstNames(string $sex): array
    {
        return FictitiousNames::firstNames($sex);
    }

    public function firstNameWeights(string $sex): array
    {
        return FictitiousNames::firstNameWeights($sex);
    }

    /**
     * @inheritDoc
     */
    public function towns(): array
    {
        return FictitiousNames::towns();
    }
}
