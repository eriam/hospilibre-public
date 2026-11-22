<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed\Decor;

use Ox\Cli\Seed\PatientBuilder;
use Ox\Cli\Seed\Referential;
use Ox\Cli\Seed\ReferentialBuilder;
use Ox\Cli\Seed\SeedContext;
use Ox\Cli\Seed\SejourBuilder;

/**
 * Quels builders composent le jeu de données.
 *
 * Le dernier degré de liberté d'un décor, et le plus rarement utile : les trois étages — référentiel,
 * patients, séjours — suffisent à la plupart des décors, qui n'ont qu'à changer leurs données. Ce joint
 * existe pour le cas où un décor doit changer la MÉCANIQUE d'un étage et non ses paramètres : un
 * établissement sans bloc opératoire, un parcours de soins qui n'existe pas ailleurs.
 *
 * L'ordre reste imposé par le moteur — référentiel, puis patients, puis séjours — parce que chaque
 * étage dépend strictement du précédent.
 */
interface SeedBuilders
{
    public function referential(SeedContext $context): ReferentialBuilder;

    public function patients(SeedContext $context): PatientBuilder;

    public function sejours(SeedContext $context, Referential $referential): SejourBuilder;
}
