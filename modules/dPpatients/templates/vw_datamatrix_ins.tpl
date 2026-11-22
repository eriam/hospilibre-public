{{*
 * @package Hospilibre\Patients
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *}}

{{mb_default var=center value=true}}

{{if $patient->status == "QUAL"}}
  <figure {{if $center}}style="text-align: center"{{/if}}>
    <img src="{{$patient->_ref_patient_ins_nir->datamatrix_ins}}" alt="datamatrix INS"/>
    <figcaption style="font-size: x-small;">{{tr}}CPatientINSNIR_datamatrix_unsigned{{/tr}}</figcaption>
  </figure>
{{/if}}
