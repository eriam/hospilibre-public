<?php
/**
 * @package Hospilibre\Mpm
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Mpm;

use Hospilibre\Hospi\CConfigServiceAbstract;

/**
 * Configuration de service liée aux moments unitaires de prescription (module MPM).
 *
 * Le socle de prescription médicamenteuse a été retiré du fork ; seule cette
 * classe de configuration reste référencée, par CService::store() et
 * CGroups::store(), pour l'invalidation du cache SHM lors de la création d'un
 * service ou d'un établissement. Elle hérite du comportement de
 * {@see CConfigServiceAbstract} (emptySHM() y est neutre), ce qui suffit à ces
 * appels. Aucun champ de configuration de moment unitaire n'est lu ailleurs.
 */
class CConfigMomentUnitaire extends CConfigServiceAbstract
{
}
