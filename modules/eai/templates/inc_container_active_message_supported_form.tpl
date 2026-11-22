{{*
 * @package Hospilibre\Eai
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *}}

{{unique_id var=uid numeric=true}}

<td class="narrow" id="actor_message_supported_{{$uid}}">
  {{mb_include module=eai template=inc_active_message_supported_form}}
</td>
<td class="narrow" style="vertical-align: middle;">
  <strong>{{tr}}{{$_message_supported->message}}{{/tr}}</strong>
</td>
<td class="narrow" style="vertical-align: middle;">
  <i class="fa fa-arrow-right"></i>
</td>
<td class="text compact">{{tr}}{{$_message_supported->message}}-desc{{/tr}}</td>
