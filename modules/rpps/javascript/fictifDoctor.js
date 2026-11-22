/**
 * @package Hospilibre\Rpps
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Réimplémentation GPL (clean-room, reconstruite depuis le template appelant
 * vw_fictif_doctor.tpl et l'action serveur add_edit_fictif_doctor, sans copier
 * la source OXOL).
 */

FictifDoctor = window.FictifDoctor || {};

/**
 * Ouvre la fenêtre d'ajout / édition d'un médecin fictif.
 *
 * @param {number|string} [medecin_id] Identifiant du médecin à éditer (absent = création)
 */
FictifDoctor.addEditDoctor = function (medecin_id) {
  var url = new Url('rpps', 'add_edit_fictif_doctor');

  if (medecin_id) {
    url.addParam('medecin_id', medecin_id);
  }

  url.requestModal(700, 500);
};
