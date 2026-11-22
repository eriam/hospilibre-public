<?php

/**
 * @package Hospilibre\eai
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Eai\Controllers\Legacy;

use Exception;
use Ox\Core\Api\Request\RequestApiBuilder;
use Ox\Core\Api\Resources\Collection;
use Ox\Core\CAppUI;
use Ox\Core\CCanDo;
use Ox\Core\CLegacyController;
use Ox\Core\CMbArray;
use Ox\Core\CMbObjectConfig;
use Ox\Core\CStoredObject;
use Ox\Core\CView;
use Ox\Interop\Eai\CConfigurationEai;
use Ox\Interop\Eai\CInteropActor;
use Ox\Interop\Eai\CInteropSender;
use Hospilibre\System\CConfiguration;
use Hospilibre\System\CConfigurationModelManager;
use Hospilibre\System\Controllers\CConfigurationController;

class CActorControllerLegacy extends CLegacyController
{
    /** @var string[] Correspondance classe de config destinataire -> libellé de norme */
    private const MAPPING_NORME_RECEIVER = [
        "CReceiverHL7v2Config"      => 'HL7v2',
        "CReceiverHL7v3Config"      => 'HL7v3',
        "CReceiverHPrimSanteConfig" => 'H\'Santé',
        "CPhastDestinataireConfig"  => 'Phast',
        "CReceiverFHIRConfig"       => 'FHIR',
    ];

    /**
     * Migration des configurations d'un acteur d'interopérabilité.
     *
     * @throws Exception
     */
    public function migrationConfigs(): void
    {
        $actor_class = CView::get('actor', 'str');
        CView::checkin();

        set_time_limit(240);

        /** @var CInteropActor $prototype */
        $prototype = new $actor_class();
        $actors    = $prototype->loadList();

        $all_configs = (new CConfigurationEai())->getAllConfigActors();
        $report      = [];

        if (!($prototype instanceof CInteropSender)) {
            // Cas des destinataires : configuration unique portée par _ref_object_configs
            foreach ($actors as $current) {
                $current->loadRefObjectConfigs();

                $norms   = [];
                $configs = CMbArray::getRecursive($all_configs, "$current->_class eai");

                $object_configs = $current->_ref_object_configs;
                if ($object_configs && $object_configs->_id) {
                    $configs = [];
                    $norms[] = self::MAPPING_NORME_RECEIVER[$object_configs->_class];

                    CConfiguration::setConfigs(
                        $this->prepareConfigs($object_configs, $configs),
                        $current
                    );
                }

                $report[$current->_guid] = [
                    'actor' => $current,
                    'norm'  => $norms,
                ];
            }

            $this->renderSmarty('inc_migration_configs', ['report' => $report]);

            return;
        }

        // Cas des émetteurs : une config par norme supportée
        $config_norme = CMbArray::getRecursive($all_configs, "$actor_class eai");

        foreach ($actors as $current) {
            $norms = [];

            // HL7
            $current->loadBackRefConfigHL7();
            if ($current->_ref_config_hl7 && $current->_ref_config_hl7->_id) {
                CConfiguration::setConfigs(
                    $this->prepareConfigs($current->_ref_config_hl7, $config_norme, 'HL7'),
                    $current
                );
                $norms[] = 'HL7';
            }

            // H'XML
            $current->loadBackRefConfigHprimXML();
            if ($current->_ref_config_hprim && $current->_ref_config_hprim->_id) {
                CConfiguration::setConfigs(
                    $this->prepareConfigs($current->_ref_config_hprim, $config_norme, 'hprimxml'),
                    $current
                );
                $norms[] = 'H\'XML';
            }

            // H'Santé
            $current->loadBackRefConfigHprimSante();
            if ($current->_ref_config_hprimsante && $current->_ref_config_hprimsante->_id) {
                CConfiguration::setConfigs(
                    $this->prepareConfigs($current->_ref_config_hprimsante, $config_norme, 'hprimsante'),
                    $current
                );
                $norms[] = 'H\'Santé';
            }

            // CDA
            $current->loadBackRefConfigCDA();
            if ($current->_ref_config_cda && $current->_ref_config_cda->_id) {
                CConfiguration::setConfigs(
                    $this->prepareConfigs($current->_ref_config_cda, $config_norme, 'cda'),
                    $current
                );
                $norms[] = 'CDA';
            }

            // Phast
            $current->loadBackRefConfigPhast();
            if ($current->_ref_config_phast && $current->_ref_config_phast->_id) {
                CConfiguration::setConfigs(
                    $this->prepareConfigs($current->_ref_config_phast, $config_norme, 'phast'),
                    $current
                );
                $norms[] = 'Phast';
            }

            // Dicom
            $current->loadBackRefConfigDicom();
            if ($current->_ref_config_dicom && $current->_ref_config_dicom->_id) {
                CConfiguration::setConfigs(
                    $this->prepareConfigs($current->_ref_config_dicom, $config_norme, 'dicom'),
                    $current
                );
                $norms[] = 'Dicom';
            }

            // FHIR
            $current->loadBackRefConfigFHIR();
            if ($current->_ref_config_fhir && $current->_ref_config_fhir->_id) {
                CConfiguration::setConfigs(
                    $this->prepareConfigs($current->_ref_config_fhir, $config_norme, 'fhir'),
                    $current
                );
                $norms[] = 'FHIR';
            }

            $report[$current->_guid] = [
                'actor' => $current,
                'norm'  => $norms,
            ];
        }

        $this->renderSmarty('inc_migration_configs', ['report' => $report]);
    }

    /**
     * Construit la table des valeurs de configuration à appliquer à partir d'un objet de config.
     *
     * @param CMbObjectConfig $object_configs
     * @param array           $configs_map
     *
     * @return array
     */
    public function prepareConfigs(
        CMbObjectConfig $object_configs,
        array $configs_map,
        ?string $filter_suffix = null
    ): array {
        if ($filter_suffix) {
            $configs_map = array_filter(
                $configs_map,
                static fn($config) => str_ends_with($config, $filter_suffix),
                ARRAY_FILTER_USE_KEY
            );
        }

        $result = [];
        foreach ($configs_map as $family_key => $family_configs) {
            foreach ($family_configs as $config_name => $config_prop) {
                $result['eai ' . $family_key . ' ' . $config_name] = $object_configs->$config_name;
            }
        }

        return $result;
    }

    /**
     * Affiche le template de configuration pour les valeurs de config d'un acteur.
     */
    public function showConfigObjectValues(): void
    {
        CCanDo::checkEdit();
        $actor_guid = CView::get('object_guid', 'str');
        CView::checkin();

        [$actor_class, $actor_uid] = explode('-', $actor_guid);

        // Configurations rattachées à la classe de l'acteur
        $all_configs   = (new CConfigurationEai())->getAllConfigActors();
        $configs_actor = CMbArray::getRecursive($all_configs, "$actor_class eai", []);

        // Déduction des formats disponibles à partir du suffixe des sections
        $formats = array_values(
            array_unique(
                array_map(
                    static fn($section_name) => substr($section_name, strrpos($section_name, '-') + 1),
                    array_keys($configs_actor)
                )
            )
        );

        $this->renderSmarty(
            'inc_config_object_values',
            [
                'mode'         => CConfigurationModelManager::getConfigurationMode(),
                'module'       => 'eai',
                'object_class' => $actor_class,
                'actor_guid'   => $actor_guid,
                'formats'      => $formats,
            ]
        );
    }

    /**
     * Exporte l'ensemble des configurations d'un acteur pour le format demandé.
     */
    public function exportConfigs(): void
    {
        CCanDo::checkEdit();
        $actor_guid = CView::get('object_guid', 'str');
        $format     = CView::get('format', 'str');
        CView::checkin();

        [$actor_class, $actor_id] = explode('-', $actor_guid, 2);

        // Ensemble des chemins de configuration pour cette classe d'acteur
        $all_path = array_keys(
            CMbArray::flattenArrayKeys((new CConfigurationEai())->getAllConfigActors()[$actor_class])
        );

        $uri = $this->generateUrl('system_get_configs');

        // Ne conserver que les chemins dont la section correspond au format demandé
        $configurations = [];
        foreach ($all_path as $path) {
            $path    = ltrim($path, ' ');
            $section = explode(' ', $path)[1];
            if (!str_ends_with($section, $format)) {
                continue;
            }

            $configuration          = new CConfiguration();
            $configuration->feature = $path;
            $configurations[]       = $configuration;
        }

        // Préparation de la requête interne vers la route de récupération
        $request = (new RequestApiBuilder())
            ->setUri($uri)
            ->setContent(json_encode((new Collection($configurations))->serialize()))
            ->buildRequestApi();
        $request->getRequest()->query->set('context', $actor_guid);

        $controller = new CConfigurationController();
        $response   = $controller->getConfigurations($request);

        if ($response->getStatusCode() !== 200) {
            CAppUI::stepAjax('CActorController-msg-fail export', UI_MSG_ERROR);
        }

        // Reconstruction des CConfiguration à partir du contenu JSON API renvoyé
        $data           = json_decode($response->getContent(), true)['data'];
        $configurations = [];
        foreach ($data as $item) {
            $attributes = $item['attributes'];

            $configuration          = new CConfiguration();
            $configuration->feature = array_key_first($attributes);
            $configuration->value   = reset($attributes);
            $configurations[]       = $configuration;
        }

        ob_clean();

        header("Content-Type: application/json");
        header("Content-Disposition: attachment; filename=\"config-actor-$format.json\"");

        // Sérialisation JSON API sans la partie meta
        $values = (new Collection($configurations))->serialize();
        unset($values['meta']);

        echo json_encode($values);

        $this->rip();
    }

    /**
     * Importe l'ensemble des configurations d'un acteur depuis un fichier.
     *
     * @return void
     * @throws Exception
     */
    public function importConfigs(): void
    {
        CCanDo::checkEdit();
        $actor_guid = CView::post('actor_guid', 'str');

        $contents = null;
        $file     = $_FILES['import'] ?? null;
        if (!empty($file)) {
            $contents = file_get_contents($file['tmp_name']);
        }
        CView::checkin();

        $actor = CStoredObject::loadFromGuid($actor_guid);
        if (!$actor || !($actor instanceof CInteropActor) || !$actor->_id || !$contents) {
            CAppUI::stepAjax('CActorController-msg-fail import', UI_MSG_ERROR);
        }

        $uri     = $this->generateUrl('system_set_configs');
        $request = (new RequestApiBuilder())
            ->setUri($uri)
            ->setMethod('PUT')
            ->setContent($contents)
            ->buildRequestApi();
        $request->getRequest()->query->set('context', $actor_guid);

        $controller = new CConfigurationController();
        $response   = $controller->setConfigurations($request);

        if ($response->getStatusCode() !== 200) {
            echo CAppUI::tr('CActorController-msg-fail import');
        }

        echo CAppUI::tr('CConfiguration-msg-modify');
    }

    /**
     * Affiche le template d'import de fichier de configuration.
     */
    public function showUploadFileConfig(): void
    {
        CCanDo::checkEdit();
        $actor_guid = CView::get('object_guid', 'str');
        CView::checkin();

        $this->renderSmarty(
            'import_config',
            [
                'actionType' => 'a',
                'action'     => 'importConfigs',
                'm'          => 'eai',
                'actor_guid' => $actor_guid,
            ]
        );
    }
}
