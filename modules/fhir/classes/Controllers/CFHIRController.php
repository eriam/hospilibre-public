<?php

/**
 * @package Hospilibre\
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Controllers;

use Exception;
use Ox\Core\CApp;
use Ox\Core\CAppUI;
use Ox\Core\CClassMap;
use Ox\Core\CController;
use Ox\Core\Chronometer;
use Ox\Core\CMbDT;
use Ox\Core\Kernel\Routing\RouterBridge;
use Ox\Interop\Connectathon\CBlink1;
use Ox\Interop\Eai\CMessageSupported;
use Ox\Interop\Fhir\Actors\CSenderFHIR;
use Ox\Interop\Fhir\CExchangeFHIR;
use Ox\Interop\Fhir\ClassMap\FHIRClassMap;
use Ox\Interop\Fhir\Exception\CFHIRException;
use Ox\Interop\Fhir\Exception\CFHIRExceptionForbidden;
use Ox\Interop\Fhir\Exception\CFHIRExceptionInformational;
use Ox\Interop\Fhir\Exception\CFHIRExceptionInvalidValue;
use Ox\Interop\Fhir\Exception\CFHIRExceptionNotSupported;
use Ox\Interop\Fhir\Interactions\CFHIRInteraction;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionCapabilities;
use Ox\Interop\Fhir\Profiles\CFHIR;
use Ox\Interop\Fhir\Request\Api\CRequestFormats;
use Ox\Interop\Fhir\Request\Api\CRequestInteraction;
use Ox\Interop\Fhir\Request\Api\CRequestResource;
use Ox\Interop\Fhir\Resources\R4\CapabilityStatement\CFHIRResourceCapabilityStatement;
use Ox\Interop\Fhir\Response\CFHIRResponse;
use Hospilibre\Admin\CUser;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\System\CExchangeHTTP;
use Hospilibre\System\CSenderHTTP;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Contrôleur d'entrée des échanges FHIR.
 */
class CFHIRController extends CController
{
    /** @var CSenderHTTP */
    public static $sender_http;

    /** @var CExchangeHTTP */
    public static $exchange_http;

    /** @var CExchangeFHIR */
    public static $exchange_fhir;

    /** @var string */
    public static $exchange_status;

    /** @var Chronometer */
    public static $chrono;

    /**
     * Prépare les motifs lumineux du périphérique Blink(1).
     *
     * @throws Exception
     */
    public static function initBlink1(): void
    {
        $device = CBlink1::getInstance();
        $device->addPattern(CFHIR::BLINK1_UNKNOW, "3,#0489B1,0.5,#000000,0.5");
        $device->addPattern(CFHIR::BLINK1_ERROR, "3,#FF0000,0.5,#000000,0.5");
        $device->addPattern(CFHIR::BLINK1_WARNING, "3,#FFFF00,0.5,#000000,0.5");
        $device->addPattern(CFHIR::BLINK1_OK, "3,#3ADF00,0.5,#000000,0.5");
    }

    /**
     * Déduit le type de ressource FHIR ciblé par la requête.
     *
     * @param Request $request
     *
     * @return string|null
     */
    public static function determineTypeResource(Request $request): ?string
    {
        // L'appel /meta impose systématiquement le CapabilityStatement.
        if ($request->attributes->get('object_class') === CFHIRInteractionCapabilities::class) {
            return CFHIRResourceCapabilityStatement::RESOURCE_TYPE;
        }

        // Sinon on s'appuie sur le paramètre "resource" du chemin de la route.
        $resource_type = $request->attributes->get('resource');

        return $resource_type ?: null;
    }

    /**
     * Ouvre le suivi d'un échange FHIR : chronomètre, traces HTTP et FHIR.
     *
     * @param Request $request
     *
     * @throws Exception
     */
    public static function start(Request $request): void
    {
        // Bascule du chronomètre applicatif vers le chronomètre dédié.
        CApp::$chrono->stop();
        static::$chrono = new Chronometer();
        static::$chrono->start();

        $method     = $request->getMethod();
        $payload    = ($method == "POST") ? $request->getContent() : $request->getRequestUri();
        $format     = (new CRequestFormats($request))->getFormat();
        $validation = true;
        $group      = CGroups::get();

        // Reconstitution du message brut : ligne de protocole + entêtes + corps.
        $header_block = $request->getProtocolVersion() . "\r\n" . $request->headers->__toString();
        $payload      = $header_block . "\r\n" . $payload;

        // Résolution de l'interaction déclarée sur la route.
        $interaction   = null;
        $object_class  = $request->attributes->get('object_class');
        if ($object_class) {
            $candidate = new $object_class();
            if ($candidate instanceof CFHIRInteraction) {
                $interaction = $candidate;
            }
        }

        // Émetteur HTTP courant.
        $sender_http = static::$sender_http;

        // Journalisation de l'échange HTTP.
        $exchange_http                = new CExchangeHTTP();
        $exchange_http->date_echange  = "now";
        $exchange_http->destinataire  = CAppUI::conf("mb_id");
        $exchange_http->function_name = $method;
        $exchange_http->input         = serialize($payload);
        $exchange_http->emetteur      = $sender_http ? $sender_http->nom : CAppUI::tr("Unknown");
        if (!$sender_http) {
            $exchange_http->http_fault = 1;
        }
        $exchange_http->response_time = static::$chrono->total;
        $exchange_http->store();

        // Journalisation de l'échange FHIR.
        $exchange_fhir                  = new CExchangeFHIR();
        $exchange_fhir->date_production = CMbDT::dateTime();
        $exchange_fhir->sender_class    = 'CSenderHTTP';
        $exchange_fhir->sender_id       = $sender_http ? $sender_http->_id : null;
        $exchange_fhir->group_id        = $group->group_id;
        $exchange_fhir->format          = $format;
        $exchange_fhir->message_valide  = $validation ? 1 : 0;
        $exchange_fhir->_message        = $payload;
        $exchange_fhir->type            = $interaction ? $interaction::NAME : null;
        $exchange_fhir->sous_type       = static::determineTypeResource($request);
        $exchange_fhir->store();

        // Émetteur introuvable : accès refusé, mais l'échange reste tracé.
        if (!static::$sender_http) {
            throw new CFHIRExceptionForbidden();
        }

        // Chargement des configurations de l'émetteur.
        $sender_http->getConfigs($exchange_fhir);

        static::$exchange_status = 'ok';
        static::$exchange_fhir   = $exchange_fhir;
        static::$exchange_http   = $exchange_http;
        static::$sender_http     = $sender_http;
    }

    /**
     * Détermine le type de ressource FHIR et vérifie sa cohérence avec le profil.
     *
     * @param Request $request
     *
     * @throws Exception
     */
    public static function resolveResource(Request $request): void
    {
        $request_resource = new CRequestResource($request);

        $resource_type = $request_resource->getResourceType();
        if (!$resource_type) {
            throw new CFHIRExceptionNotSupported('It is impossible to resolve type of FHIR Resource');
        }

        // Contrôle de cohérence entre le type demandé et le profil fourni.
        $profile = $request_resource->getProfile();
        if ($profile) {
            $class_map      = new FHIRClassMap();
            $resource_class = $class_map->resource->getResource($profile);
            if ($resource_class::RESOURCE_TYPE !== $resource_type) {
                throw new CFHIRExceptionInvalidValue(
                    "The resource type '$resource_type' and profile '$profile' required are not compatible"
                );
            }
        }

        $request->attributes->set(CRequestResource::KEY_INTERN_PROFILE, $profile);
        $request->attributes->set(CRequestResource::KEY_INTERN_RESOURCE_TYPE, $resource_type);
    }

    /**
     * Vérifie que l'interaction demandée est supportée pour la ressource visée.
     *
     * @param Request     $request
     * @param CSenderHTTP $sender_http
     *
     * @throws Exception
     */
    public static function validateInteraction(Request $request): void
    {
        $sender_fhir = new CSenderFHIR(static::$sender_http);

        // Classe d'interaction attendue sur la route.
        $interaction_class = $request->attributes->get('object_class');
        if (!$interaction_class || !CClassMap::getInstance()->getClassMap($interaction_class)) {
            throw new CFHIRExceptionInformational('Interaction class is not defined or invalid in route definition');
        }

        /** @var CFHIRInteraction $interaction */
        $interaction = new $interaction_class();

        // Résolution de la ressource ciblée.
        $request_resource = new CRequestResource($request);
        $resource_type    = $request_resource->getResourceType();
        $profile          = $request_resource->getProfile();

        // La ressource doit être supportée.
        $resource = $request_resource->getResource();
        if (!$resource) {
            $message = "Resource '$resource_type' not supported"
                . ($request_resource->getProfile() ? " with profile '$profile'" : "");
            throw new CFHIRExceptionNotSupported($message);
        }

        // L'interaction doit figurer parmi celles disponibles pour la ressource.
        $available_interactions = $sender_fhir->getAvailableInteractions($resource);
        if (!in_array($interaction::NAME, $available_interactions)) {
            $version = $resource->getResourceVersion();
            $message = "Interaction '" . $interaction::NAME . "' not supported on resource '$resource_type'[$version]"
                . ($request_resource->getProfile() ? " with profile '$profile'" : "");
            throw new CFHIRExceptionNotSupported($message);
        }

        // On isole le message supporté correspondant à cette interaction.
        $messages_supported = $sender_fhir->getMessagesSupportedForResource($resource);
        $messages_supported = array_filter(
            $messages_supported,
            static function (CMessageSupported $message_supported) use ($interaction) {
                return (new $message_supported->message)::NAME === $interaction::NAME;
            }
        );

        $message_supported = reset($messages_supported);
        $request->attributes->set(CRequestInteraction::KEY_INTERN_MESSAGE_SUPPORTED, $message_supported->_id);
    }

    /**
     * Authentifie l'émetteur à partir de l'utilisateur et mémorise le sender HTTP.
     *
     * @param Request $request
     * @param CUser   $user
     *
     * @return void
     * @throws Exception
     */
    public static function authenticateSender(Request $request, CUser $user): void
    {
        $sender_fhir = new CSenderFHIR();

        // Recherche du sender HTTP rattaché à l'utilisateur.
        $sender_http = $sender_fhir->loadFromUser($user);
        if ($sender_http) {
            // Conservation pour la suite du traitement.
            static::$sender_http = $sender_http;
            $request->attributes->set('fhir_sender_id', $sender_http->_id);
        }
    }

    /**
     * Clôt le suivi de l'échange : renseigne la réponse et relance le chrono applicatif.
     *
     * @param Response $response
     *
     * @throws Exception
     */
    public static function stop(Response $response): void
    {
        $ack_status = $response->getStatusCode() >= 400 ? 'err' : static::$exchange_status;

        // Reconstitution du message de réponse brut.
        $status_line = 'HTTP/' . $response->getProtocolVersion() . " " . $response->getStatusCode() . "\r\n";
        $output      = $status_line . $response->headers->__toString() . "\r\n" . $response->getContent();

        $exchange_http = static::$exchange_http;
        if ($exchange_http) {
            $exchange_http->date_echange  = CMbDT::dateTime();
            $exchange_http->output        = serialize($output);
            $exchange_http->response_time = static::$chrono->total;
            $exchange_http->store();
        }

        $exchange_fhir = static::$exchange_fhir;
        if ($exchange_fhir) {
            $exchange_fhir->send_datetime       = CMbDT::dateTime();
            $exchange_fhir->statut_acquittement = $ack_status;
            $exchange_fhir->acquittement_valide = 1;
            $exchange_fhir->_acquittement       = serialize($response->getContent());
            $exchange_fhir->store();
        }

        CApp::$chrono->start();
    }

    /**
     * Génère une URL absolue pour une route donnée.
     *
     * @warning do not use in production need ref with cached url generator
     *
     * @param string $route_name
     * @param array  $parameters
     *
     * @return string
     * @throws CFHIRException
     * @deprecated
     */
    public static function getUrl($route_name, $parameters = [])
    {
        $controller = new self();

        // Sans conteneur, on passe par le pont de routage statique.
        if (!$controller->container) {
            return RouterBridge::generateUrl(
                $route_name,
                $parameters,
                UrlGeneratorInterface::ABSOLUTE_URL
            );
        }

        return $controller->generateUrl(
            $route_name,
            $parameters,
            UrlGeneratorInterface::ABSOLUTE_URL
        );
    }

    /**
     * Produit la réponse HTTP à partir d'une réponse FHIR.
     *
     * @param CFHIRResponse $response
     *
     * @return Response
     */
    public function renderFHIRResponse(CFHIRResponse $response): Response
    {
        return $response->output();
    }
}
