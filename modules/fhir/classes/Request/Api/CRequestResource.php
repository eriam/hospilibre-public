<?php

/**
 * @package Hospilibre\Fhir\Request\Api
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Request\Api;

use Exception;
use Ox\Core\Api\Request\IRequestParameter;
use Ox\Interop\Fhir\Actors\CSenderFHIR;
use Ox\Interop\Fhir\CExchangeFHIR;
use Ox\Interop\Fhir\Controllers\CFHIRController;
use Ox\Interop\Fhir\Exception\CFHIRExceptionNotFound;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionCapabilities;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionCreate;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionUpdate;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Ox\Interop\Fhir\Resources\R4\CapabilityStatement\CFHIRResourceCapabilityStatement;
use Ox\Interop\Fhir\Serializers\CFHIRParser;
use Hospilibre\System\CSenderHTTP;
use Symfony\Component\HttpFoundation\Request;

class CRequestResource implements IRequestParameter
{
    /** @var string */
    public const KEY_FHIR_QUERY_PROFILE = '_profile';
    /** @var string */
    public const KEY_FHIR_QUERY_ID = '_id';
    /** @var string */
    public const KEY_FHIR_QUERY_RESOURCE_TYPE = '_type';
    /** @var string */
    public const KEY_API_ATTRIBUTE_RESOURCE = 'resource';
    /** @var string */
    public const KEY_API_ATTRIBUTE_RESOURCE_ID = 'resource_id';
    /** @var string */
    public const KEY_API_ATTRIBUTE_VERSION_ID = 'version_id';
    /** @var string */
    public const KEY_INTERN_RESOURCE_TYPE = 'resource_type';
    /** @var string */
    public const KEY_INTERN_PROFILE = 'resource_profile';

    /** @var Request */
    private $request;

    /** @var CFHIRResource */
    private $resource;

    /** @var CSenderFHIR|null */
    private $sender;

    /** @var string */
    private $resource_type;

    /** @var string */
    private $profile;

    /**
     * Construit l'objet de requete a partir de la requete HTTP entrante.
     *
     * @param Request $request
     *
     * @throws Exception
     */
    public function __construct(Request $request)
    {
        $this->request = $request;

        $senderId = $request->attributes->get('fhir_sender_id');
        if ($senderId) {
            // On reutilise l'emetteur deja charge par le controleur.
            $httpSender = CFHIRController::$sender_http;

            // Si ce n'est pas le bon, on le recharge depuis la base.
            if (!$httpSender && $httpSender->_id !== $senderId) {
                $httpSender = (new CSenderHTTP())->load($senderId);
                $httpSender->getConfigs(new CExchangeFHIR());
            }

            $this->sender = $httpSender->_id ? new CSenderFHIR($httpSender) : null;
        }

        // Determination du type de ressource concerne.
        $this->resource_type = $this->findResourceType($request);

        $this->profile = $this->findProfile();
    }

    /**
     * @param Request $request
     *
     * @return string|null
     */
    private function findResourceType(Request $request): ?string
    {
        // Type de ressource deja resolu en amont.
        $resolvedType = $request->attributes->get(self::KEY_INTERN_RESOURCE_TYPE);
        if ($resolvedType) {
            return $resolvedType;
        }

        $interactionClass = $request->attributes->get('object_class');

        // Route /metadata.
        if ($interactionClass === CFHIRInteractionCapabilities::class) {
            return CFHIRResourceCapabilityStatement::RESOURCE_TYPE;
        }

        // Routes POST (create / update) : on inspecte le corps de la requete.
        if (in_array($interactionClass, [CFHIRInteractionCreate::class, CFHIRInteractionUpdate::class])) {
            $detected = CFHIRParser::tryToDetermineResource($request->getContent());
            if ($detected) {
                // Memorisation du profil detecte.
                $request->attributes->set(self::KEY_INTERN_PROFILE, $detected->getProfile());

                return $detected::RESOURCE_TYPE;
            }
        }

        // Type explicitement porte par la route.
        $routeType = $request->attributes->get(self::KEY_API_ATTRIBUTE_RESOURCE);
        if ($routeType) {
            return $routeType;
        }

        // Type transmis via les parametres de requete.
        $queryType = $request->query->get(self::KEY_FHIR_QUERY_RESOURCE_TYPE);
        if ($queryType) {
            return $queryType;
        }

        return null;
    }

    /**
     * @return string|null
     */
    private function findProfile(): ?string
    {
        // Profil deja identifie.
        $knownProfile = $this->request->attributes->get(self::KEY_INTERN_PROFILE);
        if ($knownProfile) {
            return $knownProfile;
        }

        // Profil issu de la query string (uniquement en GET).
        $queryProfile = $this->request->query->get(self::KEY_FHIR_QUERY_PROFILE);
        if ($queryProfile && $this->request->getMethod() === Request::METHOD_GET) {
            return $queryProfile;
        }

        // Recherche a l'interieur du corps.
        $interactionClass = $this->request->attributes->get('object_class');
        // Routes POST.
        if ($interactionClass === CFHIRInteractionCreate::class || $interactionClass === CFHIRInteractionUpdate::class) {
            // todo
        }

        return null;
    }

    /**
     * @throws CFHIRExceptionNotFound
     * @throws Exception
     */
    private function findResource(): ?CFHIRResource
    {
        $resource = $this->findResourceFromSender();

        if ($resource) {
            $resource->_sender = $this->sender->getSender();

            // Chargement de l'objet metier associe a la ressource.
            $resourceId = $this->getResourceId();
            if ($resourceId) {
                $versionId = $this->getVersionId();

                $resource->loadObjectFromContext($resourceId, $versionId);
            }
        }

        return $this->resource = $resource;
    }

    /**
     * @return string|null
     */
    public function getResourceType(): ?string
    {
        return $this->resource_type;
    }

    /**
     * @return CSenderFHIR|null
     */
    public function getSender(): ?CSenderFHIR
    {
        $sender = $this->sender->getSender();

        return $sender && $sender->_id ? $this->sender : null;
    }

    /**
     * @return string|null
     */
    public function getVersionId(): ?string
    {
        return $this->request->attributes->get(self::KEY_API_ATTRIBUTE_VERSION_ID);
    }

    /**
     * @return string|null
     */
    public function getResourceId(): ?string
    {
        return $this->request->attributes->get(self::KEY_API_ATTRIBUTE_RESOURCE_ID);
    }

    /**
     * @param string      $resource_type
     * @param string|null $profile
     *
     * @return CFHIRResource|null
     * @throws Exception
     */
    private function findResourceFromSender(): ?CFHIRResource
    {
        // Toutes les ressources actives declarees dans supported_messages.
        $availableResources = $this->sender->getAvailableResources();

        if ($this->profile) {
            // Filtrage par profil.
            $matches = array_filter(
                $availableResources,
                function (CFHIRResource $candidate) {
                    return $candidate->getProfile() === $this->profile;
                }
            );
        } else {
            // Filtrage par type de ressource.
            $matches = array_filter(
                $availableResources,
                function (CFHIRResource $candidate) {
                    return $candidate->getResourceType() === $this->resource_type;
                }
            );

            // En cas de plusieurs correspondances, on privilegie la ressource FHIR.
            if (count($matches) > 1) {
                $fhirOnly = array_filter(
                    $matches,
                    function (CFHIRResource $candidate) {
                        return $candidate->isFHIRResource();
                    }
                );

                $matches = count($fhirOnly) === 1 ? $fhirOnly : $matches;
            }
        }

        // Une seule ressource retenue.
        if (count($matches) === 1) {
            return reset($matches);
        }

        return null;
    }

    /**
     * @return string
     */
    public function getProfile(): ?string
    {
        return $this->profile;
    }

    /**
     * @return CFHIRResource|null
     * @throws Exception
     */
    public function getResource(): ?CFHIRResource
    {
        if ($this->resource) {
            return $this->resource;
        }

        return $this->findResource();
    }
}
