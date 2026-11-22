{{*
 * @package Hospilibre\Maternite
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *}}

<table class="me-w100" style="font-size: 100%;">
  <tr>
    <th class="category" colspan="2">{{tr}}CDepistageGrossesse-Serology{{/tr}}</th>
  </tr>
  {{if $counter_depisage.serologie > 0}}
    {{foreach from=$immuno_serology.serologie key=_field item=value}}
      <tr>
        <th>{{mb_label class=CDepistageGrossesse field=$_field}}</th>
        <td>
          {{if $value}}
            {{$value|smarty:nodefaults}}
          {{else}}
            &mdash;
          {{/if}}
        </td>
      </tr>
    {{/foreach}}
  {{else}}
    <tr>
      <td class="empty">{{tr}}CDossierPerinat.lieu_surveillance.{{/tr}}</td>
    </tr>
  {{/if}}
</table>
