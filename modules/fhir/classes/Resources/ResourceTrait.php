<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Resources;

use Ox\Core\CStoredObject;

/**
 * Description
 */
trait ResourceTrait
{
    /** @var CStoredObject */
    protected $object;

    /** @var CFHIRResource */
    protected $resource;

    /**
     * @param CFHIRResource $resource
     * @param CStoredObject $object
     */
    public function setResource(CFHIRResource $resource, CStoredObject $object): void
    {
        $this->object   = $object;
        $this->resource = $resource;
    }
}
