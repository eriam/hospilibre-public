<?php

/**
 * @package Hospilibre\System
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\System\Controllers;

use Exception;
use Ox\Components\Cache\Exceptions\CouldNotGetCache;
use Ox\Core\Api\Exceptions\ApiException;
use Ox\Core\Api\Request\RequestApi;
use Ox\Core\Api\Resources\Item;
use Ox\Core\Cache;
use Ox\Core\CAppUI;
use Ox\Core\CController;
use Ox\Core\CModelObject;
use Ox\Core\CModelObjectCollection;
use Ox\Core\CSQLDataSource;
use Ox\Core\Module\CModule;
use Hospilibre\Admin\CUser;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\System\Api\FilterableTrait;
use Hospilibre\System\CPreferences;
use Psr\SimpleCache\InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * API Controller for CPreferences class
 */
class CPreferencesController extends CController
{
    use FilterableTrait;

    public const CACHE_PREFIX = 'CPreferencesController';

    public const NO_MODULE_PREF_NAME = 'common';

    private const ALL_MODULES = 'all';

    /**
     * @api
     */
    public function listPreferences(string $mod_name, RequestApi $request_api): JsonResponse
    {
        $module_prefs = ($mod_name === self::ALL_MODULES)
            ? $this->loadAllModulesPrefs() : $this->loadModulePrefs($mod_name);

        $prefs = CPreferences::getPrefValuesForList($module_prefs);

        return $this->returnResponse($this->applyFilter($request_api, $prefs));
    }

    /**
     * @api
     *
     * User checkRead is automatic with dependency injection
     */
    public function listUserPreferences(string $mod_name, CUser $user, RequestApi $request_api): JsonResponse
    {
        $module_prefs = ($mod_name === self::ALL_MODULES)
            ? $this->loadAllModulesPrefs() : $this->loadModulePrefs($mod_name);

        $prefs = CPreferences::getAllPrefsForList($user, $module_prefs);

        return $this->returnResponse($this->applyFilter($request_api, $prefs));
    }

    /**
     * [API] Set a list of preferences (default or for a user)
     *
     * @throws Exception|ApiException
     * @api
     */
    public function setPreferences(?CUser $user, RequestApi $request_api): Response
    {
        $restricted = $request_api->getRequest()->query->getBoolean('restricted');

        // Check permission for request
        $this->checkPermPreferences($user, $restricted);

        // Get request body data
        $preferences = $request_api->getModelObjectCollection(CPreferences::class);

        // If preference for a user, check if default preference exists before storing it
        // If at least preference does not have a default preference, throw an exception with concerned preferences.
        if ($user && $user->_id) {
            if (count($no_default_pref = $this->checkIfDefaultPreferenceExist($preferences)) > 0) {
                throw new ApiException(
                    CAppUI::tr(
                        'CPreferences-error-Preference %s does not have a default preference',
                        implode(', ', $no_default_pref)
                    ),
                    Response::HTTP_NOT_FOUND
                );
            }
        }

        /** @var CPreferences $preference */
        foreach ($preferences as $preference) {
            $value                  = $preference->value;
            $preference->user_id    = ($user) ? $user->_id : null;
            $preference->restricted = ($restricted) ? '1' : '0';
            $preference->value      = null;
            $preference->loadMatchingObjectEsc();
            $preference->value = $value;
        }

        return $this->storeCollectionAndRenderApiResponse($preferences);
    }

    /**
     * [API] Delete a list of preferences (for a user)
     *
     * @throws Exception|ApiException
     * @api
     */
    public function deletePreferences(?CUser $user, RequestApi $request_api): Response
    {
        if (!$user || !$user->_id) {
            throw new ApiException(
                CAppUI::tr('CPreferences-error-You cannot delete default preferences')
            );
        }

        $restricted = $request_api->getRequest()->query->getBoolean('restricted');

        // Check permission for request
        $this->checkPermPreferences($user, $restricted);

        $to_delete = [];
        $not_exist = [];

        /** @var Item $_pref */
        foreach ($request_api->getResource()->getItems() as $_pref) {
            /** @var CPreferences $preference */
            $preference             = $_pref->createModelObject(CPreferences::class, true)
                ->hydrateObject([CModelObject::FIELDSET_DEFAULT])
                ->getModelObject();
            $preference->user_id    = $user->_id;
            $preference->restricted = ($restricted) ? '1' : '0';

            if ($preference->loadMatchingObjectEsc()) {
                $to_delete[] = $preference->pref_id;
            } else {
                $not_exist[] = $preference->key;
            }
        }

        // If at least one preference does not exist, throw exception with concerned preferences.
        if (count($not_exist) > 0) {
            throw new ApiException(
                CAppUI::tr('Preferences does not exist %s', implode(', ', $not_exist)),
                Response::HTTP_NOT_FOUND
            );
        }

        // Delete all requested preferences
        if ($msg = (new CPreferences())->deleteAll($to_delete)) {
            throw new ApiException($msg, Response::HTTP_NOT_FOUND);
        }

        return $this->renderResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Check if requested preferences have a default preference in database
     * @throws Exception
     */
    private function checkIfDefaultPreferenceExist(CModelObjectCollection $preferences): array
    {
        $requested_pref = [];

        /** @var CPreferences $_pref */
        foreach ($preferences as $_pref) {
            $requested_pref[] = $_pref->key;
        }

        $preference             = new CPreferences();
        $existing_default_prefs = $preference->loadColumn(
            'user_preferences.key',
            [
                "key"     => CSQLDataSource::prepareIn($requested_pref),
                "user_id" => $preference->getDS()->prepare("IS NULL"),
            ]
        );

        return array_diff($requested_pref, $existing_default_prefs);
    }

    /**
     * @throws Exception|ApiException
     */
    private function checkPermPreferences(?CUser $user, bool $restricted): void
    {
        $current_user = CMediusers::get();

        // Check if current user is type Administrator if another user is requested
        if ($user && $user->_id) {
            if (($user->_id !== $current_user->_id) && !$current_user->isAdmin()) {
                throw new ApiException(
                    CAppUI::tr('CPreferences-error-You need to be an admin to set or delete other users preferences')
                );
            }
        }

        // Check if request is setting or deleting a default pref, check if admin
        if (!$user && !$current_user->isAdmin()) {
            throw new ApiException(
                CAppUI::tr('CPreferences-error-You need to be an admin to set default preferences')
            );
        }

        // Check if current user is type Administrator if restricted param is true
        if ($restricted && !$current_user->isAdmin()) {
            throw new ApiException(
                CAppUI::tr('CPreferences-error-You need to be an admin to set or delete restricted preferences')
            );
        }
    }

    /**
     * @throws ApiException
     */
    private function returnResponse(array $preferences): JsonResponse
    {
        $ressource = new Item($preferences);
        $ressource->setType('preferences');

        return $this->renderApiResponse($ressource);
    }

    private function loadAllModulesPrefs(): array
    {
        $prefs = $this->loadModulePrefs(self::NO_MODULE_PREF_NAME);
        foreach (CModule::getActive() as $_mod) {
            $prefs = array_merge($prefs, $this->loadModulePrefs($_mod->mod_name));
        }

        return $prefs;
    }

    /**
     * @throws ApiException
     * @throws InvalidArgumentException
     * @throws CouldNotGetCache
     */
    private function loadModulePrefs(string $mod_name): array
    {
        if ($mod_name !== self::NO_MODULE_PREF_NAME) {
            $mod_name = $this->getActiveModule($mod_name);
        }

        $cache = new Cache(self::CACHE_PREFIX, $mod_name, Cache::INNER_OUTER);
        if (!$cache->exists()) {
            // Load prefs names from module preferences file
            CPreferences::loadModule($mod_name);

            $cache->put(CPreferences::$modules[$mod_name] ?? []);
        }

        // Return loaded prefs
        return $cache->get();
    }
}
