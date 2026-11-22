<?php

/**
 * @package Hospilibre\System
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\System\Api;

use Ox\Core\Api\Request\RequestApi;

/**
 * Description
 */
trait FilterableTrait
{
    /** @var ArrayFilter */
    private $filter;

    private function applyFilter(RequestApi $request_api, array $array): array
    {
        if ($this->filter === null) {
            $this->filter = new ArrayFilter($request_api);
        }

        if ($this->filter->isEnabled()) {
            $array = $this->filter->apply($array);
        }

        return $array;
    }

    private function isFilterEnabled(): bool
    {
        return ($this->filter && $this->filter->isEnabled());
    }
}
