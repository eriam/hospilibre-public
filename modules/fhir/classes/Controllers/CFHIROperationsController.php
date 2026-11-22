<?php

/**
 * @package Hospilibre\Fhir\Controllers
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Controllers;

use Exception;
use Ox\Interop\Fhir\Exception\CFHIRException;
use Ox\Interop\Fhir\Profiles\CFHIR;
use Ox\Interop\Fhir\Request\Api\CRequestFHIR;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CFHIROperationsController extends CFHIRController
{
    /**
     * Point d'entree de l'operation IHE PIX.
     *
     * On demande a la ressource FHIR de traiter l'interaction portee par la
     * requete, puis on emballe le resultat dans une reponse HTTP.
     *
     * @param CRequestFHIR $request Requete FHIR entrante
     *
     * @return Response Reponse FHIR serialisee
     * @throws CFHIRException
     * @throws Exception
     * @api
     */
    public function ihepix(CRequestFHIR $request): Response
    {
        $fhirResource = $request->getResource();
        $outcome      = $fhirResource->process($request->getInteraction());

        return $this->renderFHIRResponse($outcome);
    }
}
