<?php

/**
 * @package Hospilibre\Fhir\Objects
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Utilities;

use Ox\Core\CMbArray;
use Ox\Interop\Fhir\ClassMap\FHIRClassMap;
use Ox\Interop\Fhir\Exception\CFHIRException;
use Ox\Interop\Fhir\Profiles\CFHIR;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Ox\Interop\Fhir\Utilities\SearchParameters\AbstractSearchParameter;
use Ox\Interop\Fhir\Utilities\SearchParameters\SearchParameterList;

/**
 * Représente les capacités FHIR déclarées pour un type de ressource :
 * profil, interactions supportées, profils additionnels et paramètres de recherche.
 */
class CCapabilitiesResource
{
    /** @var string */
    private $type;

    /** @var string */
    private $profile;

    /** @var string[] */
    private $supportedProfiles = [];

    /** @var string[] */
    private $interactions = [];

    /** @var SearchParameterList */
    private $searchParameters;

    /** @var string */
    private $version;

    /**
     * @param array $data Données d'initialisation (type, profile, interactions, supportedProfiles)
     */
    public function __construct(array $data = [])
    {
        $this->searchParameters = new SearchParameterList();

        $resourceType = CMbArray::get($data, 'type');
        if (!$resourceType) {
            return;
        }

        $this->setType($resourceType);
        $this->setProfile(CMbArray::get($data, 'profile', CFHIR::BASE_PROFILE . $resourceType));

        $givenInteractions = CMbArray::get($data, 'interactions');
        if ($givenInteractions) {
            $this->addInteractions($givenInteractions);
        }

        $givenProfiles = CMbArray::get($data, 'supportedProfiles');
        if ($givenProfiles) {
            $this->addSupportedProfiles($givenProfiles);
        }
    }

    /**
     * @param string $type
     *
     * @return CCapabilitiesResource
     */
    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * @param string $profile
     *
     * @return CCapabilitiesResource
     */
    public function setProfile(string $profile): self
    {
        $this->profile = $profile;

        return $this;
    }

    /**
     * @param string[] $interactions
     *
     * @return CCapabilitiesResource
     */
    public function setInteractions(array $interactions): self
    {
        $this->interactions = $interactions;

        return $this;
    }

    /**
     * @param string[] $supportedProfiles
     *
     * @return CCapabilitiesResource
     */
    public function setSupportedProfiles(array $supportedProfiles): self
    {
        $this->supportedProfiles = $supportedProfiles;

        return $this;
    }

    /**
     * Ajoute un ou plusieurs profils supportés, sans doublon.
     *
     * @param string|string[] $profiles
     *
     * @return CCapabilitiesResource
     */
    public function addSupportedProfiles($profiles): self
    {
        if (!is_array($profiles)) {
            $profiles = [$profiles];
        }

        foreach ($profiles as $profile) {
            if (in_array($profile, $this->supportedProfiles)) {
                continue;
            }

            $this->supportedProfiles[] = $profile;
        }

        return $this;
    }

    /**
     * Ajoute une ou plusieurs interactions, sans doublon.
     *
     * @param string|string[] $interactions
     *
     * @return CCapabilitiesResource
     */
    public function addInteractions($interactions): self
    {
        if (!is_array($interactions)) {
            $interactions = [$interactions];
        }

        foreach ($interactions as $interaction) {
            if (in_array($interaction, $this->interactions)) {
                continue;
            }

            $this->interactions[] = $interaction;
        }

        return $this;
    }

    /**
     * Enregistre des paramètres de recherche, en garantissant leur unicité.
     *
     * @param AbstractSearchParameter[]|AbstractSearchParameter $search_parameters
     *
     * @return $this
     */
    public function addSearchAttributes($search_parameters): self
    {
        if (!is_array($search_parameters)) {
            $search_parameters = [$search_parameters];
        }

        foreach ($search_parameters as $searchParameter) {
            if (!$searchParameter instanceof AbstractSearchParameter) {
                throw new CFHIRException(
                    'search parameter definition should be an instance of ' . AbstractSearchParameter::class
                );
            }

            // On refuse tout paramètre déjà présent dans la liste.
            if (!$this->searchParameters->addUnique($searchParameter)) {
                $duplicateName = $searchParameter->getParameterName();
                throw new CFHIRException("The parameter '$duplicateName' should be unique");
            }
        }

        return $this;
    }

    /**
     * @return string[]
     */
    public function getInteractions(): array
    {
        return $this->interactions;
    }

    /**
     * @return string[]
     */
    public function getSupportedProfiles(): array
    {
        return $this->supportedProfiles;
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return string
     */
    public function getProfile(): string
    {
        return $this->profile;
    }

    /**
     * @param string $parameter_name
     *
     * @return AbstractSearchParameter|null
     */
    public function getSearchParameters(string $parameter_name): ?AbstractSearchParameter
    {
        /** @var AbstractSearchParameter|null $parameter */
        $parameter = $this->searchParameters->get($parameter_name);

        return $parameter ?: null;
    }

    /**
     * @param string $version
     *
     * @return CCapabilitiesResource
     */
    public function setVersion(string $version): self
    {
        $this->version = $version;

        return $this;
    }

    /**
     * @return string
     */
    public function getVersion(): string
    {
        return $this->version ?: "";
    }

    /**
     * @param string[] $supported_versions
     */
    public function setSupportedVersions(array $supported_versions): self
    {
        $this->supported_versions = $supported_versions;

        return $this;
    }

    /**
     * @return CFHIRResource|null
     */
    public function getResource(?string $fhir_version = null): ?CFHIRResource
    {
        return (new FHIRClassMap())->resource->getResource($this->profile);
    }
}
