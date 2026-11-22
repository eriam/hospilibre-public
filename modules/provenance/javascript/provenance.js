/**
 * @package Hospilibre\Provenance
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

// Regroupe les interactions liees a la gestion des provenances.
Provenance = {
  // Ouvre la fenetre modale d'edition pour la provenance indiquee.
  edit: function (provenance_id) {
    const editUrl = new Url('provenance', 'ajax_edit_provenance');
    editUrl.addParam('provenance_id', provenance_id);
    editUrl.requestModal(400, 300);
  },

  // Bascule la visibilite du formulaire de provenance du patient.
  editProvenancePatient: function () {
    $$('.provenanceForm').forEach(function (formNode) {
      formNode.toggle();
    });
  },

  // Recharge la liste en appliquant la colonne et le sens de tri demandes.
  provSortBy: function (order_col, order_way) {
    const sortUrl = new Url('provenance', 'vw_provenances');
    sortUrl.addParam("order_col", order_col);
    sortUrl.addParam("order_way", order_way);
    sortUrl.requestUpdate('listProvenances');
  },

  // Rafraichit la liste des provenances de l'etablissement.
  listProvenances: function () {
    const listUrl = new Url('provenance', 'vw_provenances');
    listUrl.requestUpdate('listProvenances');
  }
};
