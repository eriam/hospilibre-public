<?php

/**
 * @package Ox\Interop\Fhir
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir;

use Exception;
use Hospilibre\System\AbstractConfigurationRegister;
use Hospilibre\System\CConfiguration;
use Hospilibre\System\ConfigurationManager;
use Ox\Interop\Eai\ConfigurationActorInterface;
use Ox\Interop\Eai\Manager\FileManager;
use Ox\Interop\Eai\Repository\PatientRepository;

/**
 * Enregistrement des paramètres de configuration propres à l'interopérabilité FHIR.
 *
 * @codeCoverageIgnore
 */
class CConfigurationFHIR extends AbstractConfigurationRegister implements ConfigurationActorInterface
{
    /**
     * Déclare les configurations liées aux objets délégués FHIR.
     */
    public function register(): void
    {
        // Fabrique d'un descripteur "custom tpl" pointant vers le template de liste
        // des objets délégués, paramétré par le type d'objet fourni.
        $buildDelegatedDescriptor = static function (string $objectType): string {
            return "custom tpl|inc_config_fhir_list_object_delegated object_type|" . $objectType;
        };

        $delegatedFhir = [
            'delegated_mapper'   => $buildDelegatedDescriptor(CExchangeFHIR::DELEGATED_OBJECT_MAPPER),
            'delegated_handle'   => $buildDelegatedDescriptor(CExchangeFHIR::DELEGATED_OBJECT_HANDLE),
            'delegated_searcher' => $buildDelegatedDescriptor(CExchangeFHIR::DELEGATED_OBJECT_SEARCHER),
        ];

        CConfiguration::register(
            [
                "CGroups"           => [
                    'fhir' => [],
                ],
                "CMessageSupported" => [
                    'fhir' => [
                        'delegated_objects' => $delegatedFhir,
                    ],
                ],
            ]
        );
    }

    /**
     * @return string[]
     */
    public function getSuffixesSectionActor(): array
    {
        return ['fhir'];
    }

    /**
     * @param ConfigurationManager $manager
     *
     * @throws Exception
     */
    public function registerStatic(ConfigurationManager $manager): void
    {
        $manager->registerStatic(
            [
                'general' => [
                    'version' => 'enum list|4.0|4.1 default|4.0',
                ],
            ]
        );
    }

    /**
     * Configurations de l'acteur objet : fusion des réglages émetteur et récepteur.
     *
     * @return array
     */
    public function getConfigurationsActor(): array
    {
        return array_merge($this->getConfigurationsSender(), $this->getConfigurationsReceiver());
    }

    /**
     * Configurations dédiées aux objets récepteurs.
     *
     * @return array
     */
    private function getConfigurationsReceiver(): array
    {
        $receiverConfig = [
            'receiver-identifiers-fhir' => [
                'encode_identifiers' => 'bool default|1',
            ],
        ];

        return [
            'CReceiverFHIR' => $receiverConfig,
        ];
    }

    /**
     * Configurations dédiées aux objets émetteurs.
     *
     * @return array
     */
    private function getConfigurationsSender(): array
    {
        $patientStrategy = 'enum list|'
            . implode('|', PatientRepository::STRATEGIES)
            . ' default|' . PatientRepository::STRATEGY_BEST
            . ' localize';

        $fileNamingStrategy = 'enum list|'
            . implode('|', FileManager::STRATEGIES_FILENAME)
            . ' default|' . FileManager::STRATEGY_FILENAME_DEFAULT
            . ' localize';

        $sharedConfig = [
            'identifiers-fhir'      => [
                'encode_identifiers' => 'bool default|1',
            ],

            'handle-patient-fhir'   => [
                'search_patient_strategy' => $patientStrategy,
            ],

            'handle-document-fhir'  => [
                'associate_category_to_a_file'      => "bool default|0",
                'define_name'                       => $fileNamingStrategy,
                'id_category_patient'               => 'num',
                'object_attach'                     => 'enum list|CPatient|CSejour|COperation|CMbObject|CFilesCategory default|CMbObject localize',
                'mode_sas'                          => 'bool default|0',
                'creation_date_file_like_treatment' => 'bool default|0',
            ],
        ];

        return [
            'CSenderSOAP'       => $sharedConfig,
            'CSenderSFTP'       => $sharedConfig,
            'CSenderFTP'        => $sharedConfig,
            'CSenderHTTP'       => $sharedConfig,
            'CSenderFileSystem' => $sharedConfig,
            'CSenderMLLP'       => $sharedConfig,
        ];
    }
}
