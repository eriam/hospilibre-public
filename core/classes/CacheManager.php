<?php

/**
 * @package Hospilibre\Core
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Core;

use DirectoryIterator;
use Exception;
use Ox\Core\Composer\CComposerScript;
use Ox\Core\Kernel\Routing\RouterBridge;
use Ox\Core\Module\CAbstractModuleCache;
use Ox\Core\Module\CModule;
use Hospilibre\System\CConfigurationModelManager;
use Hospilibre\System\ConfigurationException;
use Hospilibre\System\ConfigurationManager;
use Hospilibre\System\Controllers\CPreferencesController;

/**
 * Todo: Replace with Cache Tagging.
 *
 * Cache manager class
 */
class CacheManager
{
    /** @var string */
    private static $module_cache_class = CAbstractModuleCache::class;

    /** @var int */
    private static $types = 0;

    /** @var array $cache_values */
    public static $cache_values = [
        'all'       => true,
        'mandatory' => false,
        'css'       => false,
        'js'        => false,
        'config'    => false,
        'locales'   => false,
        'logs'      => false,
        'templates' => false,
        'devtools'  => false,
        'children'  => false,
        'core'      => false,
        'symfony'   => false,
        'modules'   => false,
    ];

    /**
     * @description Clears All Cache
     */
    public static function clearAllCache(): void
    {
        self::clearMandatoryCache();
        self::clearConfigCache();
        self::clearPreferencesCache();
        self::clearJavascriptCache();
        self::clearStylesheetsCache();
        self::clearLogsCache();
        self::clearModulesCache();
        self::clearCoreCache();
        self::clearDSNCache();
        self::clearIndexCache();
    }

    /**
     * never clear distribued cache
     *
     * @throws CMbException
     * @throws ConfigurationException
     */
    public static function clearMandatoryCache(): void
    {
        self::clearDevtoolsCache();
        self::clearSymfonyCache();
        self::clearTemplatesCache();
        self::clearRoutingCache();
        self::clearChildClasses();
        self::clearLocalesCache();
    }

    /**
     * @param string $msg
     * @param int    $type
     * @param mixed  ...$args
     */
    public static function output(string $msg, int $type = CAppUI::UI_MSG_OK, ...$args): void
    {
        static::$types++;
        if (CComposerScript::$is_running) {
            return;
        }
        CAppUI::stepAjax($msg, $type, ...$args);
    }

    /**
     * @description Clears Modules Cache
     *
     * @param string $keys Array of strings containing ModuleCache classes names
     */
    private static function clearModulesCache(string $keys = 'all'): void
    {
        $keys_array = explode('|', $keys);
        if (in_array('all', $keys_array)) {
            /* Remove modules cache */
            $cache = new Cache('CModule', 'all', Cache::INNER_OUTER);
            if (!$cache->get()) {
                static::output("Modules-shm-none", CAppUI::UI_MSG_WARNING);
            } else {
                $cache->rem();
                static::output("Modules-shm-none", CAppUI::UI_MSG_OK);
            }

            $cache = new Cache('CModule.exists', 'all', Cache::INNER_OUTER);
            $cache->rem();

            // Clear module action cache
            self::clearModuleActionCache();
        }

        /* Module specific removals */
        $module_cache_classes = self::getModuleCacheClasses();
        if (is_array($module_cache_classes) && count($module_cache_classes)) {
            foreach ($module_cache_classes as $module_cache_class) {
                /** @var CAbstractModuleCache $module_cache */
                if (in_array('all', $keys_array) || in_array($module_cache_class, $keys_array)) {
                    /** @var CAbstractModuleCache $module_cache */
                    $module_cache = new $module_cache_class();

                    if (is_subclass_of($module_cache, self::$module_cache_class, true)) {
                        $module_cache->clear();
                    }
                }
            }
        }
    }

    private static function clearModuleActionCache(): void
    {
        Cache::deleteKeys(Cache::OUTER, 'CModuleAction.getID-');
    }

    private static function clearRoutingCache(): void
    {
        /* Register tabs removal */
        Cache::deleteKeys(Cache::OUTER, 'CModule.registerTabs');
        /* Show module infos */
        Cache::deleteKeys(Cache::OUTER, 'CSystemController.showModule');
        /* Legacy Actions */
        Cache::deleteKeys(Cache::OUTER, 'CModule.matchLegacyController');
    }

    /**
     * @description Clears Locales Cache
     */
    private static function clearLocalesCache(): void
    {
        $cache = Cache::getCache(Cache::OUTER);

        /* Remove locales, at the end because otherwise, next message aren't translated */
        foreach (glob("locales/*", GLOB_ONLYDIR) as $localeDir) {
            $localeName = basename($localeDir);
            $sharedName = "locales-$localeName";

            if (!$cache->get("$sharedName-" . CAppUI::LOCALES_PREFIX)) {
                static::output("Locales-shm-none", CAppUI::UI_MSG_OK, $localeName);
                continue;
            }

            if (!Cache::deleteKeys(Cache::OUTER, "$sharedName-")) {
                static::output("Locales-shm-rem-ko", CAppUI::UI_MSG_WARNING, $localeName);
                continue;
            }

            static::output("Locales-shm-rem-ok", CAppUI::UI_MSG_OK, $localeName);
        }
    }

    /**
     * Clears Config Cache
     *
     * @throws CMbException
     * @throws ConfigurationException
     */
    private static function clearConfigCache(): void
    {
        $manager = ConfigurationManager::get();

        foreach (CModule::getInstalled() as $_mod) {
            CConfigurationModelManager::clearCache($_mod->mod_name);
            $manager->clearCache($_mod->mod_name);
        }

        static::output("ConfigValues-shm-rem-ok", CAppUI::UI_MSG_OK);
    }

    private static function clearPreferencesCache(): void
    {
        foreach (CModule::getInstalled() as $_mod) {
            $cache = new Cache(CPreferencesController::CACHE_PREFIX, $_mod->mod_name, Cache::INNER_OUTER);
            if ($cache->exists()) {
                $cache->rem();
            }
        }

        $cache = new Cache(
            CPreferencesController::CACHE_PREFIX,
            CPreferencesController::NO_MODULE_PREF_NAME,
            Cache::INNER_OUTER
        );
        if ($cache->exists()) {
            $cache->rem();
        }
    }

    /**
     * @description Clears JS Cache
     */
    private static function clearJavascriptCache(): void
    {
        $js_files = glob("tmp/*.js");
        foreach ($js_files as $_js_file) {
            unlink($_js_file);
        }
        static::output("JS-cache-ok", CAppUI::UI_MSG_OK, count($js_files));
    }

    /**
     * @description Clears CSS Cache
     */
    private static function clearStylesheetsCache(): void
    {
        $css_files = glob("tmp/*.css");
        foreach ($css_files as $_css_file) {
            unlink($_css_file);
        }
        static::output("CSS-cache-ok", CAppUI::UI_MSG_OK, count($css_files));
    }

    /**
     * @description Clears Logs Cache
     */
    private static function clearLogsCache(): void
    {
        $file_log  = CApp::getPathApplicationLog();
        $file_grep = str_replace(".log", ".grep.log", $file_log);
        if (file_exists($file_grep)) {
            unlink($file_grep);
        }
        static::output("Log-grep-cache-ok", CAppUI::UI_MSG_OK);
    }

    /**
     * @description Clears devtools Cache
     */
    private static function clearDevtoolsCache(): void
    {
        $dir = substr(CDevtools::PATH_TMP, 1, -1);
        if (is_dir($dir)) {
            $files = glob($dir . '/*');
            foreach ($files as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
        static::output("devtools-cache-removed", CAppUI::UI_MSG_OK);
    }

    /**
     * @description Clears Symfony Cache (only RouterBridge)
     * @todo clear /var ?
     */
    private static function clearSymfonyCache(): void
    {
        $dir = substr(RouterBridge::CACHE_DIR, 1, -1);
        if (is_dir($dir)) {
            $files = glob($dir . '/*');
            foreach ($files as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
        static::output("symfony-cache-removed", CAppUI::UI_MSG_OK);
    }

    /**
     * @description Clears Templates Cache
     */
    private static function clearTemplatesCache(): void
    {
        /* DO NOT use CMbPath::removed because it must be used in the installer */
        $templates_path = dirname(__DIR__, 2) . '/tmp/templates_c';

        if (is_dir($templates_path)) {
            static::emptyDir($templates_path);
            static::output("template-cache-removed", CAppUI::UI_MSG_OK);
        } else {
            static::output("template-cache-empty", CAppUI::UI_MSG_OK);
        }
    }

    /**
     * Empty a directory by recursivly deleting files and emptying child directories.
     */
    private static function emptyDir(string $path): void
    {
        $it = new DirectoryIterator($path);

        /** @var DirectoryIterator $directory */
        foreach ($it as $directory) {
            if ($directory->valid() && !$directory->isDot()) {
                if ($directory->isFile()) {
                    unlink($directory->getPathname());
                } elseif ($directory->isDir()) {
                    $dir_path = $directory->getPathname();
                    static::emptyDir($dir_path);
                    rmdir($dir_path);
                }
            }
        }
    }

    /**
     * Clears Classmap Cache
     */
    private static function clearChildClasses(): void
    {
        // Todo: Does not return number of deleted keys.
        $nb = Cache::deleteKeys(Cache::OUTER, "CApp.getChildClasses");
        static::output("Children-cache-ok", CAppUI::UI_MSG_OK, $nb);
    }

    /**
     * Clear Core Cache
     */
    private static function clearCoreCache(): void
    {
        // Todo: Does not return number of deleted keys.
        $nb = Cache::deleteKeys(Cache::OUTER, 'CCSSLoader');
        static::output("CSS-list-cache-ok", CAppUI::UI_MSG_OK, $nb);

        // Todo: Does not return number of deleted keys.
        $nb = Cache::deleteKeys(Cache::OUTER, 'CConfiguration');
        static::output("CConfiguration-list-cache-ok", CAppUI::UI_MSG_OK, $nb);

        // Todo: Does not return number of deleted keys.
        $nb = Cache::deleteKeys(Cache::OUTER, 'CModelObject');
        static::output("CModelObject-list-cache-ok", CAppUI::UI_MSG_OK, $nb);
    }

    /**
     * Clear DSN Cache
     *
     * @return void
     */
    private static function clearDSNCache(): void
    {
        // Todo: Does not return number of deleted keys.
        $nb = Cache::deleteKeys(Cache::OUTER, 'CSQLDataSource');
        static::output("Datasource-list-cache-ok", CAppUI::UI_MSG_OK, $nb);
    }

    /**
     * Clear object indexer cache
     * @return void
     */
    private static function clearIndexCache(): void
    {
        $nb = CObjectIndexer::removeIndexes();
        static::output("Index-list-cache-ok", CAppUI::UI_MSG_OK, $nb);
    }

    /**
     * Returns an array of class names
     *
     * @return array|bool
     */
    public static function getModuleCacheClasses()
    {
        try {
            return CClassMap::getInstance()->getClassChildren(self::$module_cache_class);
        } catch (Exception $e) {
            static::output($e->getMessage(), CAppUI::UI_MSG_WARNING);

            return false;
        }
    }

    /**
     * Clears cache (all of it, or only sections using the keys argument)
     *
     * @param string $keys    String containing pipe separated values
     * @param string $modules String containing pipe separated values
     */
    public static function cacheClear(string $keys = 'all', string $modules = 'all'): int
    {
        /* Getting default cache values */
        $cache_values = self::$cache_values;

        /* Extracting cache values to clear */
        $cache_keys_array = explode('|', trim($keys));

        if (is_array($cache_keys_array) && count($cache_keys_array)) {
            /* Disable clear all mecanism, might be reactivated if default or invalid argument is supplied */
            $cache_values['all'] = false;
            /* Loop over cache keys supplied in argument */
            foreach ($cache_keys_array as $cache_key) {
                /* If cache key does not exist in default values, prevent from altering $cache_value array */
                if (array_key_exists($cache_key, $cache_values)) {
                    $cache_values[$cache_key] = true;
                }
            }
        }

        foreach ($cache_values as $cache_key => $cache_value) {
            if ($cache_value === true) {
                switch ($cache_key) {
                    case 'all':
                        self::clearAllCache();
                        break;

                    case 'mandatory':
                        self::clearMandatoryCache();
                        break;

                    case 'locales':
                        self::clearLocalesCache();
                        break;

                    case 'css':
                        self::clearStylesheetsCache();
                        break;

                    case 'js':
                        self::clearJavascriptCache();
                        break;

                    case 'templates':
                        self::clearTemplatesCache();
                        break;

                    case 'devtools':
                        self::clearDevtoolsCache();
                        break;

                    case 'config':
                        self::clearConfigCache();
                        break;

                    case 'logs':
                        self::clearLogsCache();
                        break;

                    case 'children':
                        self::clearChildClasses();
                        break;

                    case 'core':
                        self::clearCoreCache();
                        break;

                    case 'modules':
                        self::clearModulesCache($modules);
                        self::clearRoutingCache();
                        break;
                    case 'symfony':
                        self::clearSymfonyCache();
                        break;

                    default:
                        /* Explicitly do nothing */
                }
            }
        }

        return static::$types;
    }
}
