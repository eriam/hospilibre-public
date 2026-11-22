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
 * Les trois étages du moteur, tels qu'un décor les obtient sauf raison contraire.
 *
 * La plupart des décors n'ont rien à changer ici : ce qui varie d'un établissement à l'autre est dans
 * le catalogue et les proportions, pas dans la mécanique. Cette implémentation est donc ce que tout
 * décor réutilise, et le joint n'existe que pour le cas où l'un d'eux devrait changer un étage.
 */
final class StandardBuilders implements SeedBuilders
{
    public function referential(SeedContext $context): ReferentialBuilder
    {
        return new ReferentialBuilder($context);
    }

    public function patients(SeedContext $context): PatientBuilder
    {
        return new PatientBuilder($context);
    }

    public function sejours(SeedContext $context, Referential $referential): SejourBuilder
    {
        return new SejourBuilder($context, $referential);
    }
}
