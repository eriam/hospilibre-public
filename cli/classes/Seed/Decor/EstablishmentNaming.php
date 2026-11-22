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
 * Comment l'établissement se nomme, et ce qu'il porte comme adresse.
 *
 * UNE RÈGLE QUI NE SE NÉGOCIE PAS : le nom produit ne doit pas pouvoir être confondu avec celui d'un
 * établissement réel. Le décor par défaut implante l'hôpital dans une vraie commune mais lui donne un
 * patron INVENTÉ, parce que « Centre Hospitalier de Bordeaux » désignerait une institution existante.
 * Un décor qui relâcherait cette règle produirait un jeu de données qu'on ne peut pas montrer.
 */
interface EstablishmentNaming
{
    /**
     * @param array<string, string>|null $town lieu d'implantation, si le décor a une géographie
     *
     * @return array{text: string, code: string, cp: string|null, ville: string|null}
     * @throws SeedException
     */
    public function name(SeedRandom $random, ?array $town): array;
}
