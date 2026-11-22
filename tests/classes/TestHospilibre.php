<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Tests;

use Exception;
use Ox\Core\CAppUI;
use Ox\Core\CRequest;
use Ox\Core\CSQLDataSource;
use Ox\Core\CStoredObject;
use Ox\Core\Kernel\Kernel;
use Hospilibre\System\CFixturesReference;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\System\CConfiguration;
use PDO;
use PDOException;
use ReflectionClass;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RequestContext;

/**
 * Trait TestHospilibre
 * horizontal composition of behavior (db, error, config, pref ...)
 */
trait TestHospilibre
{
    public $base_url;
    public $dsn;
    public $db_type;
    public $db_host;
    public $db_name;
    public $db_username;
    public $db_password;

    public $errorCount;

    private $newConfigs = ['standard' => [], 'groups' => [], 'static' => []];
    private $oldConfigs = ['standard' => [], 'groups' => [], 'static' => []];

    private $newPrefs = ['standard' => []];
    private $oldPrefs = [];

    private static $object_from_fixtures = [];


    /**
     * Set attribut from conifg (db_name, db_user, db_pass, base_url ...)
     * Global case:  http://XXX.XX.XX.XX/hospilibre/
     * Release case: http://XXX.XX.XX.XX/hospilibre_MonthName/
     *
     *
     * @param string $instance_name Instance name
     *
     * @return void
     * @throws Exception
     */
    public function setContext($instance_name = null)
    {
        // db
        $this->db_type     = CAppUI::conf("db std dbtype");
        $this->db_host     = CAppUI::conf("db std dbhost");
        $this->dsn         = "{$this->db_type}:host={$this->db_host}";
        $this->db_username = CAppUI::conf("db std dbuser");
        $this->db_password = CAppUI::conf("db std dbpass");
        $this->db_name     = CAppUI::conf("db std dbname");

        // Global case
        $conf_base_url  = CAppUI::conf("base_url");
        $this->base_url = $conf_base_url;

        // Release case
        //$release_file = __DIR__ . "/../../release.xml";
        //    if (file_exists($release_file)) {
        //      // If the file exists, we read it
        //      $file_content = file_get_contents($release_file);
        //      preg_match("/[0-9]{4}_[0-9]{2}/", $file_content, $matches);
        //      $month_number = explode("_", $matches[0])[1];
        //
        //      // Check if month number is odd or even and set url and db name
        //      $branch         = ($month_number & 1) ? "odd" : "even";
        //      $this->base_url = dirname($conf_base_url) . "/hospilibre_$branch";
        //      $this->db_name  = "hospilibre_$branch";
        //    }
        //    // Special case when a test runs on a given instance
        //    else {
        //      if ($instance_name) {
        //        $this->base_url = dirname($conf_base_url) . "/$instance_name";
        //        $this->db_name  = $instance_name;
        //      }
        //    }
    }

    /**
     * Set a standard configuration (config.php or config_db)
     *
     * @param string $path  Configuration path
     * @param mixed  $value Configuration value
     *
     * @return array
     */
    private static function setStandardConfig(string $path, $value): array
    {
        // Getting old value
        $parts     = explode(' ', $path);
        $first     = array_shift($parts);
        $old_value = ($GLOBALS['dPconfig'][$first]) ?? [];

        foreach ($parts as $_part) {
            $old_value = ($old_value[$_part]) ?? [];
        }

        // Values are scalars
        $old_value = (is_array($old_value)) ? null : $old_value;

        // Setting new value
        $parts = explode(' ', $path);
        $node  = &$GLOBALS['dPconfig'];

        foreach ($parts as $_key) {
            $node = &$node[$_key];
        }

        $node = $value;

        return [$path => $old_value];
    }

    /**
     * Try to connect to the specify DB with login/password
     *
     * @param string $db_name Optional db_name
     *
     * @return PDO PDO object
     */
    public function dbConnect($db_name = null)
    {
        try {
            return new PDO(
                $this->dsn . ";" . ($db_name ? "dbname=$db_name" : ""),
                $this->db_username,
                $this->db_password
            );
        } catch (PDOException $e) {
            die("DB ERROR: " . $e->getTraceAsString());
        }
    }

    /**
     * @param string $object_class
     * @param string $tag
     * @param bool   $clone
     *
     * @return CStoredObject
     * @throws TestsException
     * @throws Exception
     */
    public function getObjectFromFixturesReference(
        string $object_class,
        string $tag,
        bool $clone = false
    ): CStoredObject {
        if (!isset(self::$object_from_fixtures[$object_class][$tag])) {
            if (!is_subclass_of($object_class, CStoredObject::class)) {
                throw new TestsException("{$object_class} is not a subclass_of CStoredObject");
            }

            /** @var CStoredObject $target */
            $target           = new $object_class();
            $fr               = new CFixturesReference();
            $fr->object_class = $target->_class;
            $fr->tag          = $tag;
            $fr->loadMatchingObject();

            if (!$fr->_id) {
                throw new TestsException("Undefined reference {$object_class} with tag {$tag}");
            }

            $target->load($fr->object_id);
            if (!$target->_id) {
                throw new TestsException("Undefined object {$object_class} with id {$target->_id}");
            }

            self::$object_from_fixtures[$object_class][$tag] = $target;
        }

        $object = self::$object_from_fixtures[$object_class][$tag];

        // If true, return a new stored object with same values as object loaded from fixture
        if ($clone) {
            return $this->cloneModelObject($object);
        }

        return $object;
    }

    /**
     * @param string $class The class name
     * @param int    $nb    Nb of objects
     *
     * @return mixed|CStoredObject|CStoredObject[]
     * @throws TestsException
     * @deprecated Use Fixtures and getObjectFromFixturesReference or create sample object
     */
    public function getRandomObjects($class, $nb = 1)
    {
        $this->markTestSkipped('NEVER use random objects in test case, MUST create new ones OR use fixtures #ADR-0053');
        //        $nb = ($nb > 100) ? 100 : $nb;
        //
        //        $where = [];
        //
        //        // todo replace with specific fixtures
        //        if ($class === CUser::class || $class === CMediusers::class) {
        //            static $test_user_id, $admin_user_id;
        //
        //            if ($test_user_id === null) {
        //                $user                = new CUser();
        //                $user->user_username = TestBootstrap::USER_PHPUNIT;
        //                $test_user_id        = $user->loadMatchingObjectEsc();
        //            }
        //
        //            if ($admin_user_id === null) {
        //                $user                = new CUser();
        //                $user->user_username = TestBootstrap::USER_ADMIN;
        //                $admin_user_id       = $user->loadMatchingObjectEsc();
        //            }
        //
        //            // Never get this users
        //            $where = [
        //                'user_id' => CSQLDataSource::prepareNotIn(
        //                    [$test_user_id, $admin_user_id]
        //                ),
        //            ];
        //        }
        //
        //        /** @var CStoredObject $object */
        //        $object = new $class();
        //
        //        $objects = $object->loadList(
        //            $where,
        //            null,
        //            $this->getLimit($object->countList($where), $nb),
        //            null,
        //            null,
        //            null,
        //            null,
        //            false
        //        );
        //
        //        if (empty($objects) || count($objects) < $nb) {
        //            throw new TestsException("Not enough object in bdd for {$class}, contribute to fixtures !");
        //        }
        //
        //        if ($nb === 1) {
        //            return reset($objects);
        //        }
        //
        //        return $objects;
    }

    /**
     * Count error number by querying the db
     *
     * @return int error count
     */
    public function getErrorCount()
    {
        $excludedErrorType = ["notice"];
        $db                = $this->dbConnect($this->db_name);
        $sql               = "SELECT COUNT(`error_log_id`) as errorCount FROM `error_log`
            WHERE `error_type` NOT IN ('" . implode("', '", $excludedErrorType) . "');";
        $statement         = $db->prepare($sql);
        $statement->execute();
        $row       = $statement->fetch();
        $statement = null;
        $db        = null;

        return $row['errorCount'];
    }

    /**
     * Set MB configuration according to test comment
     *
     * @param array $configs Array containing config path, value and type (standard or groups)
     *
     * @return void
     */
    protected function setConfig($configs)
    {
        if (empty($configs['standard']) && empty($configs['groups']) && empty($configs['static'])) {
            return;
        }

        foreach ($configs as $_type => $_configs) {
            foreach ($_configs as $_path => $_value) {
                if ($_type == 'standard') {
                    // todo change  in GLOBAL $dpconfig
                    $this->oldConfigs['standard'][] = static::setStandardConfig($_path, $_value);
                } elseif ($_type === 'static') {
                    $this->oldConfigs['static'][] = static::setStaticConfig($_path, $_value);
                } else {
                    // todo change in cache static
                    $this->oldConfigs['groups'][] = static::setGroupsConfig($_path, $_value);
                }
            }
        }
    }

    /**
     * Get the current test function comments
     *
     * @return null|string
     */
    private function getFunctionComments()
    {
        global $mbpath;
        $mbpath = __DIR__ . "/../../";

        // HTTP_HOST is undefined when running with PHP CLI
        $_SERVER["HTTP_HOST"] = "";

        $reflectionClass = new ReflectionClass(get_class($this));

        $method_name = $this->getName();
        if (!$reflectionClass->hasMethod($method_name)) {
            // @dataProvider case
            $method_name = explode(' ', $this->getName())[0];
            if (!$reflectionClass->hasMethod($method_name)) {
                return;
            }
        }

        $method = $reflectionClass->getMethod($method_name);

        return $method->getDocComment();
    }

    /**
     * Parse function comment in order to retrieve config information
     *
     * @param string $type  Type of comment to parse (config or pref)
     * @param array  $array Array to append parsed values
     *
     * @return array
     */
    private function parseComment($type, &$array)
    {
        $comments = $this->getFunctionComments();

        if (preg_match_all("/.*{$type}\s(?<{$type}>.*)/", $comments, $matches)) {
            foreach ($matches[$type] as $_match) {
                $pos   = strrpos($_match, ' ');
                $path  = trim(substr($_match, 0, $pos));
                $value = trim(substr($_match, $pos + 1));

                // Needed fo groups config
                if ($pos = strpos($path, '[CConfiguration]') !== false) {
                    $path = str_replace('[CConfiguration] ', '', $path);

                    if (strpos($path, '[static]') === 0) {
                        $array['static'] += [str_replace('[static] ', '', $path) => $value];
                    } else {
                        $array['groups'] += [$path => $value];
                    }
                } else {
                    $array['standard'] += [$path => $value];
                }
            }
        }

        return $array;
    }

    /**
     * Sets a groups configuration (CConfiguration class)
     * The value is set for global and all groups
     *
     * @param string $path  Configuration path
     * @param mixed  $value Configuration value
     *
     * @return string
     */
    private static function setGroupsConfig(string $path, $value): string
    {
        CConfiguration::setValueInCache($path, $value, CGroups::loadCurrent()->_guid);

        return $path;
    }

    private static function setStaticConfig(string $path, $value): string
    {
        CConfiguration::setValueInCache($path, $value, 'static');

        return $path;
    }


    /**
     * Get the last object identifier created
     *
     * @param string $table Table name where object is stored
     * @param string $key   Optional primary key of the table
     *
     * @return null|string
     */
    public function getObjectId($table, $key = null)
    {
        $ds = CSQLDataSource::get("std");

        if (!$key) {
            $key = $table . "_id";
        }

        $r = new CRequest();
        $r->addSelect($key);
        $r->addTable($table);
        $r->addOrder("$key DESC");

        return $ds->loadResult($r->makeSelect());
    }

    /**
     * @param int $table_count
     * @param int $row_count
     *
     * @return string
     */
    private function getLimit(int $table_count, int $row_count)
    {
        $max_rand = ($table_count - $row_count);
        $start    = ($max_rand < 0) ? 0 : rand(0, $max_rand);

        return "{$start},{$row_count}";
    }

    protected function getKernelForTests(): Kernel
    {
        static $kernel;

        if (!$kernel) {
            $kernel = new Kernel('test', false);
            $kernel->boot();
        }

        return $kernel;
    }

    protected function getRequestForApi(string $route_name = null): Request
    {
        $request = new Request([], [], ['_route' => $route_name]);

        $reflection = new ReflectionClass($request);
        $prop       = $reflection->getProperty('pathInfo');
        $prop->setAccessible(true);
        $prop->setValue($request, '/api/status');

        return $request;
    }
}
