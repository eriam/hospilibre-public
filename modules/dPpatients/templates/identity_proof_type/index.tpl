{{*
 * @package Hospilibre\Patients
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *}}

{{mb_script module=patients script=IdentityProofType}}

<div>
  {{mb_include module=patients template=identity_proof_type/filters}}
</div>

<div id="list_identity_proof_types">
  {{mb_include module=patients template=identity_proof_type/list}}
</div>
