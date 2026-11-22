<?php

/**
 * @package Hospilibre\Cabinet
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Cabinet\Controllers;

use Exception;
use Ox\AppFine\Client\CAppFineClient;
use Ox\Core\Api\Request\RequestApi;
use Ox\Core\Api\Resources\Item;
use Ox\Core\CController;
use Ox\Core\CMbArray;
use Ox\Core\Kernel\Exception\HttpException;
use Hospilibre\Cabinet\CConsultation;
use Hospilibre\Sante400\CIdSante400;
use Symfony\Component\HttpFoundation\Response;

class CConsultationController extends CController
{
    /**
     * @param RequestApi   $requestApi
     * @param CConsultation $consultation
     *
     * @return Response
     * @throws Exception
     * @api
     */
    public function updateConsultation(RequestApi $requestApi, string $consultation_id): Response
    {
        // todo check permissions
        $consultation = new CConsultation();
        $consultation->load($consultation_id);

        if (!$consultation->_id) {
            throw new HttpException(Response::HTTP_NOT_FOUND, 'Consultation not found');
        }

        $data                  = $requestApi->getContent(true, 'utf-8');
        $arrivee               = CMbArray::get($data, 'arrivee');
        $consultation->arrivee = $arrivee;

        if ($msg = $consultation->store()) {
            throw new HttpException(Response::HTTP_CONFLICT, $msg);
        }

        $item = Item::createFromRequest($requestApi, $consultation);

        return $this->renderApiResponse($item);
    }
}
