<?php

/**
 * @package Hospilibre\FHIR\Controllers
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Controllers;

use Exception;
use Ox\Interop\Fhir\Exception\CFHIRException;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionHistory;
use Ox\Interop\Fhir\Request\Api\CRequestFHIR;
use Ox\Interop\Fhir\Resources\R4\CapabilityStatement\CFHIRResourceCapabilityStatement;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CFHIRInteractionsController extends CFHIRController
{
    /**
     * Route de lecture (read).
     *
     * @param CRequestFHIR $request Requête FHIR entrante
     *
     * @return Response
     * @throws Exception
     * @api
     */
    public function read(CRequestFHIR $request): Response
    {
        $target = $request->getResource();

        $processed = $target->process($request->getInteraction());

        return $this->renderFHIRResponse($processed);
    }

    /**
     * Route d'historique (history).
     *
     * @param CRequestFHIR $request Requête FHIR entrante
     *
     * @return Response
     * @throws Exception
     * @api
     */
    public function history(CRequestFHIR $request): Response
    {
        $target = $request->getResource();

        /** @var CFHIRInteractionHistory $interaction */
        $interaction = $request->getInteraction()
            ->setVersionId($request->getResourceVersion())
            ->setResourceId($request->getResourceId());

        $object = $target->getObject();
        if ($object->_id && !$object->_history) {
            $object->loadHistory();
        }

        return $this->renderFHIRResponse($target->process($interaction));
    }

    /**
     * Route du CapabilityStatement (metadata).
     *
     * @param CRequestFHIR $request
     *
     * @return Response
     * @throws CFHIRException
     * @throws Exception
     * @api
     */
    public function metadata(CRequestFHIR $request): Response
    {
        /** @var CFHIRResourceCapabilityStatement $target */
        $target = $request->getResource();
        $target->setBaseFHIRVersion($target->getFHIRVersion());

        return $this->renderFHIRResponse($target->process($request->getInteraction()));
    }

    /**
     * Route de recherche (search).
     *
     * @param CRequestFHIR $request Requête FHIR entrante
     *
     * @return Response
     * @throws Exception
     * @api
     */
    public function search(CRequestFHIR $request): Response
    {
        $target = $request->getResource();

        return $this->renderFHIRResponse($target->process($request->getInteraction()));
    }

    /**
     * Création d'une ressource (create).
     *
     * @param CRequestFHIR $request Requête FHIR entrante
     *
     * @return Response
     * @throws Exception
     * @api
     */
    public function create(CRequestFHIR $request): Response
    {
        $target  = $request->getResource();
        $payload = $request->getContent();

        return $this->renderFHIRResponse($target->process($request->getInteraction(), $payload));
    }

    /**
     * Mise à jour d'une ressource (update).
     *
     * @param String   $resource    Nom de la ressource
     * @param int|null $resource_id Identifiant de la ressource
     * @param Request  $request     Requête
     *
     * @return Response
     * @throws CFHIRException
     * @throws Exception
     * @api
     */
    public function update(CRequestFHIR $request): Response
    {
        $target  = $request->getResource();
        $payload = $request->getContent();

        return $this->renderFHIRResponse($target->process($request->getInteraction(), $payload));
    }

    /**
     * Suppression d'une ressource (delete).
     *
     * @param String   $resource    Nom de la ressource
     * @param int|null $resource_id Identifiant de la ressource
     * @param Request  $request     Requête
     *
     * @return Response
     * @throws CFHIRException
     * @throws Exception
     * @api
     */
    public function delete(CRequestFHIR $request): Response
    {
        $target = $request->getResource();

        return $this->renderFHIRResponse($target->process($request->getInteraction()));
    }
}
