{{*
 * @package Hospilibre\Maternite
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *}}

<table class="me-w100" style="font-size: 100%;">
  <tr>
    <th class="category" colspan="4">{{tr}}CGrossesse-Stays during pregnancy{{/tr}}</th>
  </tr>
  <tr>
    <td>
      <ul>
        {{foreach from=$grossesse->_ref_sejours item=_sejour}}
          {{if $_sejour->type != "consult"}}
            <li>{{$_sejour->_view}} &mdash; {{$_sejour->_ref_praticien}}</li>
          {{/if}}
        {{foreachelse}}
          <li>{{tr}}CSejour.none{{/tr}}</li>
        {{/foreach}}
      </ul>
    </td>
  </tr>
</table>
