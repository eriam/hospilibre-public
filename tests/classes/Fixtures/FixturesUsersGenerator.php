<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Tests\Fixtures;

use Exception;
use Hospilibre\Admin\CUser;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Mediusers\CFunctions;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\System\CFirstNameAssociativeSex;

abstract class FixturesUsersGenerator
{
    /** @var CFunctions */
    protected static $function;

    /** @var string */
    private const TYPE = 'Médecin';

    /**
     * CMediusers generator
     **
     * @return CMediusers|null
     * @throws FixturesException
     * @throws Exception
     */
    private static function makeUser(): ?CMediusers
    {
        $user_info = static::getUserInfo();

        $mediuser                   = new CMediusers();
        $mediuser->function_id      = static::getFunction()->_id;
        $mediuser->_user_first_name = $user_info->firstname;
        $mediuser->_user_last_name  = $user_info->lastname;
        $mediuser->_user_username   = $user_info->username;
        $mediuser->_user_sexe       = $user_info->sex;
        $mediuser->commentaires     = "User created with Fixtures";

        // type id
        $type_id              = array_keys(CUser::$types, self::TYPE);
        $mediuser->_user_type = reset($type_id);

        // store CMediusers
        if ($msg = $mediuser->store()) {
            throw new FixturesException($msg);
        }

        return $mediuser;
    }

    /**
     * Generate number of users requested
     *
     * @param int $nb
     *
     * @return array
     * @throws Exception
     */
    public static function generate(int $nb): array
    {
        $users = [];
        for ($i = 1; $i <= $nb; $i++) {
            $users[] = static::makeUser();
        }

        return $users;
    }

    /**
     * @return CFunctions
     * @throws FixturesException
     */
    private static function getFunction(): CFunctions
    {
        if (static::$function === null) {
            return static::existOrCreateFunction();
        }

        return static::$function;
    }

    /**
     * Check if group and function with text Fixtures exist
     * @return CFunctions
     * @throws FixturesException
     * @throws Exception
     */
    private static function existOrCreateFunction(): CFunctions
    {
        $group       = new CGroups();
        $group->code = "FIXTURES_GROUP";
        if (!$group->loadMatchingObjectEsc()) {
            $group->_name = "Fixtures";
            if ($msg = $group->store()) {
                throw new FixturesException($msg);
            }
        }

        $function           = new CFunctions();
        $function->group_id = $group->_id;
        $function->text     = "Fixtures_Function";
        if (!$function->loadMatchingObjectEsc()) {
            $function->type  = "administratif";
            $function->color = "FFFFFF";
            if ($msg = $function->store()) {
                throw new FixturesException($msg);
            }
        }

        return static::$function = $function;
    }

    /**
     * Get random firstname and sex from first_names_associative_sex table
     *
     * @return object
     * @throws Exception
     */
    protected static function getUserInfo(): object
    {
        $fnas_class = new CFirstNameAssociativeSex();
        $max        = $fnas_class->countList();

        if ($max > 0) {
            $limit      = rand(0, ($max - 1));
            $first_name = $fnas_class->loadList(null, null, "$limit, 1");
            $name = reset($first_name);
        } else {
            $name = new CFirstNameAssociativeSex();
            $name->firstname = uniqid();
            $name->sex = rand(0, 1) ? 'm' : 'f';
        }

        $last_name = uniqid('fixtures');
        $user_name = $name->firstname[0] . $last_name;

        return (object)[
            'firstname' => $name->firstname,
            'lastname'  => $last_name,
            'username'  => $user_name,
            'sex'       => $name->sex,
        ];
    }
}
