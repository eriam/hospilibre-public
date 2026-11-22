<?php
/**
 * @package Hospilibre\System
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\System\Controllers;

use Exception;
use Ox\Core\Api\Exceptions\ApiException;
use Ox\Core\Api\Request\Filter;
use Ox\Core\Api\Request\RequestApi;
use Ox\Core\Api\Request\RequestFilter;
use Ox\Core\Api\Resources\Collection;
use Ox\Core\Api\Resources\Item;
use Ox\Core\CClassMap;
use Ox\Core\CController;
use Ox\Core\CModelObject;
use Ox\Core\CSQLDataSource;
use Ox\Core\CStoredObject;
use Ox\Core\Kernel\Exception\ControllerException;
use Hospilibre\System\CObjectClass;
use Hospilibre\System\CUserAction;
use Hospilibre\System\CUserLog;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class CHistoryController
 */
class CHistoryController extends CController
{
    /**
     * @param RequestApi $request_api
     * @param string      $resource_type
     * @param int         $resource_id
     *
     * @return Response
     * @throws Exception
     * @api
     */
    public function list(string $resource_type, int $resource_id): Response
    {
        $object   = $this->getObjectFromRequirements($resource_type, $resource_id);
        $resource = new Collection($object->_ref_logs);

        return $this->renderApiResponse($resource);
    }

    /**
     * @param RequestApi $request_api
     *
     * @param string      $resource_type
     * @param int         $resource_id
     * @param int         $history_id
     *
     * @return Response
     * @throws ApiException
     * @throws ControllerException
     * @api
     */
    public function show(RequestApi $request_api, string $resource_type, int $resource_id, int $history_id): Response
    {
        $object  = $this->getObjectFromRequirements($resource_type, $resource_id);
        $history = $object->_ref_logs;

        $log_expected = null;
        foreach ($history as $log) {
            if ((int)$log->_id === (int)$history_id) {
                $log_expected = $log;
                break;
            }
        }

        if ($log_expected === null) {
            throw new ControllerException(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                'Invalid resource identifiers.',
                [],
                2
            );
        }

        if ($request_api->getRequest()->query->getBoolean('loadResource')) {
            /** @var CStoredObject $target */
            $target   = $object->loadListByHistory($history_id);
            $resource = new Item($target);
            $resource->setType($resource_type);
        } else {
            $resource = new Item($log);
            $resource->setModelRelations('all');
        }

        return $this->renderApiResponse($resource);
    }

    /**
     * @param string $resource_type
     * @param int    $resource_id
     *
     * @return CStoredObject
     * @throws ControllerException|Exception
     */
    private function getObjectFromRequirements(string $resource_type, int $resource_id)
    {
        $object_class = CModelObject::getClassNameByResourceType($resource_type);
        /** @var CStoredObject $object */
        $object = new $object_class;
        $object->load($resource_id);

        if (!$object->_id) {
            throw new ControllerException(Response::HTTP_INTERNAL_SERVER_ERROR, 'Invalid resource identifiers.', [], 1
            );
        }

        $object->loadLogs();

        return $object;
    }

    /**
     * @param RequestApi $request_api
     *
     * @return Response
     * @throws ApiException
     * @api
     */
    private function listGenerique(RequestApi $request_api): Response
    {
        /** @var RequestFilter $request_filter */
        $request_filter = $request_api->getRequestParameter(RequestFilter::class);

        // Map resource_name & resource_id to object_class & object_id
        $object_class = null;
        $object_id    = null;

        $new_filters = [];
        // update filters
        foreach ($request_filter as $filter_key => $filter) {
            /** @var Filter $filter */
            if ($filter->getKey() === 'resource_type') {
                $object_class  = CClassMap::getSN(CModelObject::getClassNameByResourceType($filter->getValue()));
                $new_filters[] = new Filter('object_class', RequestFilter::FILTER_EQUAL, $object_class);
                $request_filter->removeFilter($filter_key, false);
            }

            if ($filter->getKey() === 'resource_id') {
                $new_filters[] = new Filter('object_id', RequestFilter::FILTER_EQUAL, $filter->getValue());
                $request_filter->removeFilter($filter_key, false);
            }
        }

        // add filters
        foreach ($new_filters as $new_filter) {
            /** @var Filter $new_filter */
            $request_filter->addFilter($new_filter);
        }
        $where_log = $request_filter->getSqlFilters(CSQLDataSource::get('std'));


        foreach ($request_filter as $filter_key => $filter) {
            /** @var Filter $filter */
            if ($filter->getKey() === 'object_class') {
                $object_class_id = CObjectClass::getID($filter->getValue());
                $request_filter->addFilter(
                    new Filter('object_class_id', RequestFilter::FILTER_EQUAL, $object_class_id)
                );
                $request_filter->removeFilter($filter_key, false);
            }
        }
        $where_action = $request_filter->getSqlFilters(CSQLDataSource::get('std'));

        // CUserLog
        $log      = new CUserLog();
        $list_log = $log->loadList($where_log, $request_api->getSortAsSql(), $request_api->getLimitAsSql());
        //CStoredObject::massLoadFwdRef($list_log, "object_id");

        // CUserAction
        $action      = new CUserAction();
        $list_action = $action->loadList($where_action, $request_api->getSortAsSql(), $request_api->getLimitAsSql());
        //CStoredObject::massLoadFwdRef($list_action, "object_id");

        // merge log & action
        foreach ($list_action as $_user_action) {
            $_user_log = new CUserLog();
            $_user_log->loadFromUserAction($_user_action);
            $list_log[$_user_log->_id] = $_user_log;
        }
        $logs = $list_log;


        $resource = Collection::createFromRequest($request_api, $logs);
        $resource->createLinksPagination($request_api->getOffset(), $request_api->getLimit());

        return $this->renderApiResponse($resource);
    }

    /**
     * @param RequestApi $request
     *
     * @param int         $history_id
     *
     * @return Response
     * @throws ApiException
     * @api
     */
    private function showGenerique(RequestApi $request, $history_id): Response
    {
        // todo vérifier les droits sur l'objet
        if ($history_id >= CUserLog::USER_ACTION_START_AUTO_INCREMENT) {
            $log = new CUserAction();
        } else {
            $log = new CUserLog();
        }

        $log->load($history_id);

        $resource = Item::createFromRequest($request, $log);

        return $this->renderApiResponse($resource);
    }

}
