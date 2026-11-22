<?php

/**
 * @package Hospilibre\Cabinet
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\Etablissement\Tests\Fixtures;

use Hospilibre\Etablissement\CGroups;
use Hospilibre\Mediusers\CFunctions;
use Ox\Tests\Fixtures\Fixtures;
use Ox\Tests\Fixtures\FixturesException;

/**
 * Fixtures module dPetablissement
 */
class GroupsFixtures extends Fixtures
{
    public const TAG_GROUP    = "GROUP_FIXTURES_G1";
    public const TAG_GROUP_2  = "GROUP_FIXTURES_G2";
    public const TAG_FUNCTION = "FUNCTION_FIXTURES_F1";

    /**
     * @inheritDoc
     * @throws FixturesException
     */
    public function load(): void
    {
        $this->generateGroup(self::TAG_GROUP);
        $group_2 = $this->generateGroup(self::TAG_GROUP_2);

        $this->generateFunction($group_2, self::TAG_FUNCTION);
    }


    /**
     * @throws FixturesException
     */
    public function generateGroup(string $tag): CGroups
    {
        $group                 = new CGroups();
        $group->_name          = "Etablissement de test";
        $group->code           = "etab_test";
        $group->raison_sociale = "Etablissement de test";

        $this->store($group, $tag);

        return $group;
    }

    /**
     * @throws FixturesException
     */
    public function generateFunction(CGroups $group, string $tag): void
    {
        $function           = new CFunctions();
        $function->group_id = $group->_id;
        $function->text     = "Fonction de test";
        $function->type     = "administratif";
        $function->color    = "FFFFFF";

        $this->store($function, $tag);
    }
}
