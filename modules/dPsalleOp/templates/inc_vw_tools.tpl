{{*
 * @package Hospilibre\Transport
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *}}

{{mb_script module=salleOp script=geste_perop ajax=true}}

<table class="tbl me-no-align me-margin-top-10">
  <tr>
    <th>{{tr}}Action{{/tr}}</th>
    <th>{{tr}}Status{{/tr}}</th>
  </tr>
  <tr>
    <td>
      <button class="tick me-primary"
              title="{{tr}}CProtocoleGestePeropItem-msg-Change the category by the perop gestures of this category which will be associated with the perop gesture protocols{{/tr}}"
              onclick="GestePerop.changeProtocoleItem();">
        {{tr}}CProtocoleGestePeropItem-action-Modify protocol item context{{/tr}}
      </button>
    </td>
    <td id="change_item"></td>
  </tr>
</table>
