{{*
 * @package Hospilibre\Stock
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *}}

<script>
  cutTools = function () {
    new Url("stock", "ajax_cut_units").open();
  };
</script>

<table class="tbl">
  <tr>
    <td>
      {{if "bcb"|module_active && "dPmedicament"|module_active && 'Hospilibre\Medicament\CMedicament::getBase'|static_call:null == "bcb"}}
        <button type="button" class="search" onclick="cutTools();">Découper les unités</button>
      {{/if}}
    </td>
  </tr>
</table>
