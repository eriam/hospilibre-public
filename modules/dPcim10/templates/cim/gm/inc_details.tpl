{{*
 * @package Hospilibre\Cim10
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
*}}

{{if $code|instanceof:'Hospilibre\Cim10\Gm\CCodeCIM10GM'}}
  {{mb_include module=cim10 template=cim/gm/inc_details_code}}
{{elseif $code|instanceof:'Hospilibre\Cim10\Gm\CCategoryCIM10GM'}}
  {{mb_include module=cim10 template=cim/gm/inc_details_category category=$code}}
{{/if}}