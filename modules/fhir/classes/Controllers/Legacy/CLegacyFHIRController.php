<?php

/**
 * @package Mediboard\Fhir\Controllers
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Controllers\Legacy;

use Ox\Core\Api\Request\RequestApiBuilder;
use Ox\Core\Api\Resources\Collection;
use Ox\Core\CAppUI;
use Ox\Core\CLegacyController;
use Ox\Core\CStoredObject;
use Ox\Core\CView;
use Ox\Interop\Eai\CMessageSupported;
use Ox\Interop\Fhir\CExchangeFHIR;
use Ox\Interop\Fhir\Profiles\CFHIR;
use Hospilibre\System\CConfiguration;
use Hospilibre\System\Controllers\CConfigurationController;

/**
 * Controleur legacy pour les echanges FHIR.
 *
 * @package Ox\Interop\Fhir\Controllers
 */
class CLegacyFHIRController extends CLegacyController
{
    public function fhir_purge_messages_supported(): void
    {
        $this->checkPermAdmin();

        $ids = CView::get('message_ids', 'str');
        CView::checkin();

        // Rien a purger si aucun identifiant n'a ete transmis.
        if (!$ids) {
            CAppUI::stepAjax('Aucun message selectionné', UI_MSG_ALERT);

            return;
        }

        (new CMessageSupported())->deleteAll($ids);
        CAppUI::stepAjax('Suppression de ' . count($ids) . ' messages supportés');
    }

    public function fhir_list_message_supported(): void
    {
        $this->checkPermAdmin();

        // Regroupement des messages supportes par acteur (couple classe/id).
        $grouped_by_actor = [];
        foreach ($this->fhir_list_messages() as $supported) {
            /** @var CMessageSupported $supported */
            $guid                      = $supported->object_class . '-' . $supported->object_id;
            $grouped_by_actor[$guid][] = $supported;
        }

        // Chargement de l'objet metier correspondant a chaque acteur.
        $actors = [];
        foreach (array_keys($grouped_by_actor) as $guid) {
            $actors[$guid] = CStoredObject::loadFromGuid($guid);
        }

        $this->renderSmarty(
            'inc_list_fhir_message_supported',
            ['actor_messages' => $grouped_by_actor, 'actors' => $actors]
        );
    }

    private function fhir_list_messages(): array
    {
        $supported = new CMessageSupported();

        $where = [
            'message' => $supported->getDS()->prepareIn(CFHIR::$evenements),
        ];

        return $supported->loadList($where);
    }

    /**
     * @return void
     * @throws \Ox\Core\Api\Exceptions\ApiException
     * @throws \Ox\Core\Api\Exceptions\ApiRequestException
     */
    public function updateDelegatedValues(): void
    {
        $supported_ids = CView::get('message_supported_ids', 'str');

        $delegated_values = CView::get('delegated_values', 'str');
        if ($delegated_values) {
            $delegated_values = json_decode(stripslashes($_GET['delegated_values']));
        }
        CView::checkin();

        // On a besoin a la fois des cibles et des valeurs a deleguer.
        if (!$delegated_values || !$supported_ids) {
            return;
        }

        $messages_supported = (new CMessageSupported())->loadAll($supported_ids);

        // Construction des configurations pour les seuls types delegables.
        $configurations = [];
        foreach ($delegated_values as $type => $value) {
            if (!in_array($type, CExchangeFHIR::DELEGATED_OBJECTS)) {
                continue;
            }

            $configuration          = new CConfiguration();
            $configuration->feature = "fhir delegated_objects $type";
            $configuration->value   = $value;

            $configurations[] = $configuration;
        }

        if (!$configurations) {
            return;
        }

        $uri     = $this->generateUrl('system_set_configs');
        $payload = json_encode((new Collection($configurations))->serialize());

        foreach ($messages_supported as $supported) {
            $request = (new RequestApiBuilder())
                ->setUri($uri)
                ->setMethod('PUT')
                ->setContent($payload)
                ->buildRequestApi();
            $request->getRequest()->query->set('context', $supported->_guid);

            // Application des configurations pour ce message supporte.
            $response = (new CConfigurationController())->setConfigurations($request);

            if ($response->getStatusCode() !== 200) {
                CAppUI::stepAjax('CLegacyFHIRController-msg-fail configure message supported', UI_MSG_ERROR);
            }
        }

        CAppUI::stepAjax('CLegacyFHIRController-msg-configure message supported', UI_MSG_OK);
    }
}
