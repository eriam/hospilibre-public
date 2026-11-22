<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Request\Api;

use Ox\Core\CClassMap;
use Ox\Core\CMbModelNotFoundException;
use Ox\Interop\Eai\CMessageSupported;
use Ox\Interop\Fhir\Interactions\CFHIRInteraction;
use Symfony\Component\HttpFoundation\Request;

/**
 * Résout l'interaction FHIR portée par une requête HTTP entrante.
 */
class CRequestInteraction
{
    /** @var string Clé d'attribut interne référençant le message supporté associé. */
    public const KEY_INTERN_MESSAGE_SUPPORTED = "interaction_supported_message";

    /** @var Request */
    private $request;

    /** @var CFHIRInteraction */
    private $interaction;

    /**
     * @param Request $request Requête HTTP à analyser.
     */
    public function __construct(Request $request)
    {
        $this->request     = $request;
        $this->interaction = $this->findInteraction();
    }

    /**
     * Retourne l'interaction déduite de la requête.
     *
     * @return CFHIRInteraction
     */
    public function getInteraction(): CFHIRInteraction
    {
        return $this->interaction;
    }

    /**
     * Instancie l'interaction FHIR à partir des attributs de la requête, le cas échéant.
     *
     * @return CFHIRInteraction|null
     * @throws CMbModelNotFoundException
     */
    private function findInteraction(): ?CFHIRInteraction
    {
        $interactionClass = $this->request->attributes->get('object_class');

        // Sans classe cible ou si celle-ci est inconnue du registre, aucune interaction possible.
        if (!$interactionClass || !CClassMap::getInstance()->getClassMap($interactionClass)) {
            return null;
        }

        $interaction = new $interactionClass();

        // On n'accepte que les objets respectant le contrat d'interaction FHIR.
        if (!$interaction instanceof CFHIRInteraction) {
            return null;
        }

        // Rattachement éventuel du message supporté désigné dans les attributs.
        $messageSupportedId = $this->request->attributes->get(self::KEY_INTERN_MESSAGE_SUPPORTED);

        if ($messageSupportedId) {
            $interaction->setMessageSupported(CMessageSupported::findOrFail($messageSupportedId));
        }

        return $interaction;
    }
}
