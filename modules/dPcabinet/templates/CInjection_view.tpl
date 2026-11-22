{{*
 * @package Hospilibre\Cabinet
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
*}}

{{mb_include module=system template=CMbObject_view object=$object}}

<button class="edit" onclick="Vaccination.editVaccinationView({{$object->_id}})">{{tr}}Modify{{/tr}}</button>
