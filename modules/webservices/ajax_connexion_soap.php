<?php
/**
 * @package Hospilibre\Webservices
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CAppUI;
use Ox\Core\CCanDo;
use Ox\Core\CMbException;
use Ox\Core\CValue;
use Ox\Interop\Webservices\CSourceSOAP;
use Hospilibre\System\CExchangeSource;

/**
 * Test connexion
 */
CCanDo::checkAdmin();

// Check params
if (null == $exchange_source_name = CValue::get("exchange_source_name")) {
  CAppUI::stepAjax("Aucun nom de source d'échange spécifié", UI_MSG_ERROR);
}
/** @var CSourceSOAP $exchange_source */
$exchange_source = CExchangeSource::get($exchange_source_name, CSourceSOAP::TYPE, true, null, false);

if (!$exchange_source) {
  CAppUI::stepAjax("Aucune source d'échange disponible pour ce nom : '$exchange_source_name'", UI_MSG_ERROR);
}

if (!$exchange_source->host) {
  CAppUI::stepAjax("Aucun hÏ„te pour la source d'échange : '$exchange_source_name'", UI_MSG_ERROR);
}

$options = array(
  "encoding" => $exchange_source->encoding,
    "loggable" => false
);

$soap_client = $exchange_source->getClient();
if ($soap_client->hasError()) {
  CAppUI::stepAjax("Impossible de joindre la source de donnée : '$exchange_source_name'", UI_MSG_ERROR);
}
else {
  CAppUI::stepAjax("Connecté Î° la source '$exchange_source_name'");
}

try {
  $soap_client->checkServiceAvailability();
}
catch (CMbException $e) {
  $e->stepAjax();
}
