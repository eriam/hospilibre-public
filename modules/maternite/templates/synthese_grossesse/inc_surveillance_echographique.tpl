{{*
 * @package Hospilibre\Maternite
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *}}

<table class="print me-no-box-shadow me-w100" style="font-size: 100%;">
  <tr>
    <th class="category" colspan="2">{{tr}}CDossierPerinat-suivi_grossesse-echographies{{/tr}}</th>
  </tr>
  <tr>
    <td>
      {{foreach from=$list_children key=key_num item=echographies name=echographies_enfants}}
        {{mb_include module=maternite template=synthese_grossesse/inc_synthese_enfant}}
      {{foreachelse}}
        <table class="me-w100" style="font-size: 100%;">
          <tr>
            <td class="empty">{{tr}}CSuiviGrossesse.echographie.{{/tr}}</td>
          </tr>
        </table>
      {{/foreach}}
    </td>
  </tr>
</table>
