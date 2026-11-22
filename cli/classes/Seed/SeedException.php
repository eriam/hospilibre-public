<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

use Exception;

/**
 * Toute erreur du moteur de seed.
 *
 * Volontairement une seule classe : le seed est un outil d'opérateur, pas une API. Ce qui
 * compte est le message, pas la possibilité d'attraper finement un type d'échec.
 */
class SeedException extends Exception
{
}
