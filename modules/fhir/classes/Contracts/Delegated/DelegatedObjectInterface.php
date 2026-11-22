<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Contracts\Delegated;

/**
 * Description
 */
interface DelegatedObjectInterface
{
    /**
     * Filter delegated for only profiles list here. You should return profile class (namespaced).
     * If array is empty no filter will be applied.
     *
     * @return array
     */
    public function getProfiles(): array;
}
