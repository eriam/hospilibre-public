{{*
 * @package Hospilibre\Cim10
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *}}

<script>
  listDPforbidden = function (page) {
    new Url("cim10", "ajax_sejours_dp_forbidden")
      .addParam('page', page)
      .requestModal("60%", "70%");
  };
</script>

<table class="tbl">
  <tr>
    <th>{{tr}}Action{{/tr}}</th>
    <th>{{tr}}Status{{/tr}}</th>
  </tr>
  <tr>
    <td colspan="2">
      <button type="button" class="search" onclick="listDPforbidden(0);">
        {{tr}}CCodeCIM10-action-Search for stays with a prohibited DP{{/tr}}
      </button>
    </td>
  </tr>
</table>
