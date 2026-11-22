<?php

/**
 * @package Hospilibre\Fhir\Controllers
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Controllers;

use Exception;
use Hospilibre\Admin\CUser;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Files\CFile;
use Hospilibre\Sante400\CIdSante400;
use Ox\AppFine\Server\CAppFineServer;
use Ox\Core\Api\Request\RequestApi;
use Ox\Core\CAppUI;
use Ox\Core\CMbArray;
use Ox\Core\CMbDT;
use Ox\Interop\Fhir\Actors\CSenderFHIR;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\OperationOutcome\CFHIRDataTypeOperationOutcomeIssue;
use Ox\Interop\Fhir\Exception\CFHIRException;
use Ox\Interop\Fhir\Exception\CFHIRExceptionInformational;
use Ox\Interop\Fhir\Interactions\CFHIRInteraction;
use Ox\Interop\Fhir\Request\Api\CRequestFHIR;
use Ox\Interop\Fhir\Request\Api\CRequestFormats;
use Ox\Interop\Fhir\Resources\R4\OperationOutcome\CFHIRResourceOperationOutcome;
use Ox\Interop\Fhir\Response\CFHIRResponse;
use Ox\Interop\Fhir\ValueSet\CFHIRIssueType;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contrôleur FHIR dédié aux interactions AppFine.
 *
 * @package Ox\Interop\Fhir\Controllers
 */
class CFHIRAppFineController extends CFHIRController
{
    /**
     * Route de recherche AppFine.
     *
     * @param String       $resource Nom de la ressource
     * @param CRequestFHIR $request  Requête
     *
     * @return Response
     * @throws Exception
     *
     * @api
     */
    public function searchAppFine(CRequestFHIR $request): Response
    {
        $fhirResource = $request->getResource();
        $fhirResource->setInteraction($request->getInteraction());

        return $this->renderFHIRResponse($fhirResource->interactionSearchAppFine());
    }

    /**
     * Crée un utilisateur patient pour AppFine.
     *
     * @param CRequestFHIR $request Requête
     *
     * @return Response
     * @throws Exception
     *
     * @api
     */
    public function patient_user_appFine(CRequestFHIR $request): Response
    {
        $formats       = new CRequestFormats($request->getRequest());
        $chosenFormat  = $formats->getFormat();
        $payload       = $request->getContent(true);

        $senderWrapper = new CSenderFHIR();
        $senderWrapper->loadFromUser(CUser::get());

        $senderActor = $senderWrapper->getSender();
        if (!$senderActor || !$senderActor->_id) {
            throw new CFHIRExceptionInformational(CAppUI::tr("CSenderHTTP.none"));
        }

        $activationCode = CMbArray::get($payload, "code");
        $patientId      = CMbArray::get($payload, "patient_id");
        $patientIdClient = CMbArray::get($payload, "patient_id_client");

        $diagnostics = CAppFineServer::createPatientUserAPI(
            $senderWrapper->getSender(),
            $patientId,
            $patientIdClient,
            $activationCode
        );

        $outcome = new CFHIRResourceOperationOutcome();
        $outcome->addIssue(
            CFHIRDataTypeOperationOutcomeIssue::build(
                [
                    "code"        => CFHIRIssueType::TYPE_INFORMATIONAL,
                    "diagnostics" => $diagnostics,
                ]
            )
        );

        $interaction         = new CFHIRInteraction();
        $interaction->format = $chosenFormat;
        $interaction->setResource($outcome);

        return $this->renderFHIRResponse(new CFHIRResponse($interaction, $chosenFormat));
    }

    /**
     * Prévisualise un formulaire dans AppFine.
     *
     * @param RequestApi $request_api
     *
     * @return string
     * @throws CFHIRException
     * @api
     */
    public function preview_form_appFine(RequestApi $request): Response
    {
        $rawData = $request->getContent();
        $decoded = json_decode(CMbArray::get($rawData, "data"), true);

        $formGuid = CMbArray::get($decoded, "object_guid");
        if (!$formGuid) {
            throw new CFHIRException("Invalid argument 'ex_class_id'");
        }

        $encodedXml = CMbArray::get($decoded, "form_xml");
        if (!$encodedXml) {
            throw new CFHIRException("Invalid argument 'form_xml'");
        }

        $fileName = CMbArray::get($decoded, "file_name");
        if (!$fileName) {
            throw new CFHIRException("Invalid argument 'file_name'");
        }

        $currentGroup = CGroups::loadCurrent();
        $currentUser  = CUser::get();
        $extension    = "xml";
        $mimeType     = "application/mbForm";
        $decodedXml   = base64_decode($encodedXml);
        $viewFormTag  = CAppFineServer::getObjectTagAppFineViewForm($currentGroup->_id);

        // Génération du fichier associé au formulaire
        $formFile = new CFile();
        $formFile->setObject($currentUser);
        $formFile->file_name = "$fileName.$extension";
        $formFile->file_type = $mimeType;
        $formFile->file_date = CMbDT::dateTime();
        $formFile->fillFields();
        $formFile->updateFormFields();
        $formFile->setContent($decodedXml);
        if ($storeError = $formFile->store()) {
            throw new CFHIRException($storeError);
        }

        // Enregistrement de l'identifiant santé 400 avec le tag, pour une suppression ultérieure
        $externalId               = new CIdSante400();
        $externalId->object_id    = $formFile->_id;
        $externalId->object_class = $formFile->_class;
        $externalId->id400        = $formGuid;
        $externalId->tag          = $viewFormTag;
        if ($idStoreError = $externalId->store()) {
            $formFile->delete();
            throw new CFHIRException($idStoreError);
        }

        return $this->renderJsonResponse(json_encode(["file_id" => $formFile->_id]));
    }
}
