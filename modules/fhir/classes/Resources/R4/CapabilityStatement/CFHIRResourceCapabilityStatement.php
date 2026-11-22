<?php

/**
 * @package Hospilibre\Fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Resources\R4\CapabilityStatement;

use DOMDocument;
use DOMNode;
use Exception;
use Ox\Core\CApp;
use Ox\Core\CAppUI;
use Ox\Interop\Fhir\Actors\CSenderFHIR;
use Ox\Interop\Fhir\CFHIRXPath;
use Ox\Interop\Fhir\Contracts\Resources\ResourceCapabilityStatementInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCanonical;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeDate;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeDateTime;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\CapabilityStatement\CFHIRDataTypeCapabilityStatementInteraction;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\CapabilityStatement\CFHIRDataTypeCapabilityStatementResource;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\CapabilityStatement\CFHIRDataTypeCapabilityStatementRest;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\CapabilityStatement\CFHIRDataTypeCapabilityStatementSoftware;
use Ox\Interop\Fhir\Exception\CFHIRException;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionCapabilities;
use Ox\Interop\Fhir\Resources\CFHIRDomainResource;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Ox\Interop\Fhir\Utilities\CCapabilities;
use Ox\Interop\Fhir\Utilities\CCapabilitiesResource;

/**
 * FIHR CapabilityStatement resource
 */
class CFHIRResourceCapabilityStatement extends CFHIRDomainResource implements ResourceCapabilityStatementInterface
{
    // constants
    /** @var string */
    public const RESOURCE_TYPE = 'CapabilityStatement';

    /** @var string */
    public const VERSION_NORMATIVE = '4.0';

    // attributes
    /** @var CFHIRDataTypeString */
    public $version;

    /** @var CFHIRDataTypeCode */
    public $status;

    /** @var CFHIRDataTypeDateTime */
    public $date;

    /** @var CFHIRDataTypeString */
    public $publisher;

    /** @var CFHIRDataTypeCode */
    public $kind;

    /** @var CFHIRDataTypeCapabilityStatementSoftware */
    public $software;

    /** @var CFHIRDataTypeCode */
    public $fhirVersion;

    /** @var CFHIRDataTypeCode */
    public $acceptUnknown;

    /** @var CFHIRDataTypeCode[] */
    public $format;

    /** @var CFHIRDataTypeCapabilityStatementRest[] */
    public $rest;

    // attributes
    /** @var string */
    private $base_fhir_version;

    /**
     * @return CCapabilitiesResource
     */
    public function generateCapabilities(): CCapabilitiesResource
    {
        return parent::generateCapabilities()
            ->addInteractions([CFHIRInteractionCapabilities::NAME]);
    }

    /**
     * @param string $data
     */
    public function deserialize(string $data): ?CCapabilities
    {
        $dom = new DOMDocument();
        $dom->loadXML($data);
        $dom->formatOutput = true;

        $xpath = new CFHIRXPath($dom);

        $resources = [];
        $resource_nodes = $xpath->query('/fhir:' . $this::RESOURCE_TYPE . "/fhir:rest/fhir:resource");
        /** @var DOMNode $node */
        foreach ($resource_nodes as $node) {
            $resource_type = $xpath->queryAttributNode('fhir:type', $node, 'value');
            $profile       = $xpath->queryAttributNode('fhir:profile', $node, 'value');

            $interaction_nodes = $xpath->query('fhir:interaction', $node);
            $interactions = [];
            foreach ($interaction_nodes as $interaction_node) {
                $interactions[] = $xpath->queryAttributNode('fhir:code', $interaction_node, 'value');
            }

            $supported_profiles = [];
            $supported_profiles_nodes = $xpath->query('fhir:supportedProfile', $node);
            /** @var DOMNode $profiles_node */
            foreach ($supported_profiles_nodes as $profiles_node) {
                if ($value = $profiles_node->attributes->getNamedItem('value')->nodeValue) {
                    $supported_profiles[] = $value;
                }
            }

            $resources[] = [
                'type'              => $resource_type,
                'profile'           => $profile,
                'supportedProfiles' => $supported_profiles,
                'interactions'      => $interactions,
                'updateCreate'      => false // allow client to define its own id on update request (PUT)
            ];
        }

        return (new CCapabilities())
            ->addResources($resources);
    }

    /**
     * @return void
     * @throws Exception
     */
    public function interactionCapabilities(): void
    {
        if (!$this->_sender) {
            throw new CFHIRException('Impossible to find sender');
        }

        $this->status = new CFHIRDataTypeCode("active");

        $this->date = new CFHIRDataTypeDate(CApp::getVersion()->getReleaseDate());

        $this->publisher = new CFHIRDataTypeString("Not provided");

        $this->kind = new CFHIRDataTypeCode("instance");

        $this->software = CFHIRDataTypeCapabilityStatementSoftware::build(
            [
                "name"        => new CFHIRDataTypeString(CAppUI::conf("product_name")),
                "version"     => new CFHIRDataTypeString((string) CApp::getVersion()),
                "releaseDate" => new CFHIRDataTypeDate(CApp::getVersion()->getReleaseDate()),
            ]
        );

        $this->fhirVersion = new CFHIRDataTypeCode($this->base_fhir_version);

        $this->acceptUnknown = new CFHIRDataTypeCode("no");

        $this->format = [
            new CFHIRDataTypeCode("application/fhir+xml"),
            new CFHIRDataTypeCode("application/fhir+json"),
        ];

        $enc_sender_fhir = new CSenderFHIR($this->_sender);

        $available_resources = $enc_sender_fhir->getAvailableResources();

        $resources = [];
        foreach ($available_resources as $resource) {
            if ($resource::RESOURCE_TYPE === self::RESOURCE_TYPE) {
                continue;
            }

            $interactions = $this->_sender ?
                $enc_sender_fhir->getAvailableInteractions($resource) : $resource->getInteractions();

            $interactions = array_map(
                function ($interaction) {
                    return CFHIRDataTypeCapabilityStatementInteraction::build(["code" => new CFHIRDataTypeCode($interaction)]);
                },
                $interactions
            );

            $profile = new CFHIRDataTypeCanonical($resource->getProfile());

            /** @var CFHIRResource[] $extension_classes */

            if ($this->_sender) {
                $supportedProfile = $enc_sender_fhir->getAvailableProfiles($resource);
            } else {
                $supportedProfile  = [];
                foreach ($resource->findProfiles() as $extension_resource) {
                    if ($extension_resource::PROFILE_TYPE) {
                        $supportedProfile[] = new CFHIRDataTypeCanonical($extension_resource::PROFILE_TYPE);
                    }
                }
            }

            $resources[] = CFHIRDataTypeCapabilityStatementResource::build(
                [
                    'type'             => new CFHIRDataTypeString($resource::RESOURCE_TYPE),
                    'profile'          => $profile,
                    'supportedProfile' => $supportedProfile,
                    'interaction'      => $interactions,
                ]
            );
        }

        $this->rest[] = CFHIRDataTypeCapabilityStatementRest::build(
            [
                "mode"     => new CFHIRDataTypeCode("server"),
                "resource" => $resources,
            ]
        );
    }

    /**
     * @param string $base_fhir_version
     */
    public function setBaseFHIRVersion(string $base_fhir_version): void
    {
        $this->base_fhir_version = $base_fhir_version;
    }
}
