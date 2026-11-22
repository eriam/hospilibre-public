/**
 * @package Hospilibre\Bloc
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

MultiSalle = {
  salles_ids: null,
  chir_id:    null,
  date:       null,

  reloadOps: function (distinct_plages) {
    var query = new Url('bloc', 'ajax_list_operations_multisalle');
    query.addParam('salles_ids[]', MultiSalle.salles_ids, true);
    query.addParam('date', MultiSalle.date);
    query.addParam('chir_id', MultiSalle.chir_id);

    if (!Object.isUndefined(distinct_plages)) {
      query.addParam('distinct_plages', distinct_plages);
    }

    query.requestUpdate('list_ops');
  },

  reloadPlanning: function () {
    var query = new Url('bloc', 'ajax_vw_planning_operations');
    query.addParam('salles_ids[]', MultiSalle.salles_ids, true);
    query.addParam('date', MultiSalle.date);
    query.addParam('chir_id', MultiSalle.chir_id);
    query.requestUpdate('planning_ops');
  },

  reloadOpsPlanning: function () {
    MultiSalle.reloadOps();
    MultiSalle.reloadPlanning();
  },

  onMenuClick: function (event, object_id, elt) {
    var alterForm = getForm('alterOp');

    // Repositionnement d'une intervention par glissement
    if (event === 'hslip') {
      var plagesCount = $('planningWeek').select('.plage_planning').length;

      if (plagesCount > 2) {
        MultiSalle.choosePlage(object_id, MultiSalle.date, MultiSalle.chir_id);
      } else {
        var slipForm = getForm('moveOp');
        $V(slipForm.operation_id, object_id);
        onSubmitFormAjax(slipForm, MultiSalle.reloadOpsPlanning);
      }

      return;
    }

    // Envoi de l'intervention hors du planning
    if (event === 'cancel') {
      $V(alterForm._move, 'out');
      $V(alterForm.operation_id, object_id);
      onSubmitFormAjax(alterForm, MultiSalle.reloadOpsPlanning);
      return;
    }

    // Ouverture de la fenêtre de pause
    if (event === 'pause') {
      MultiSalle.editPause(object_id);
      return;
    }

    // Deplacement relatif (avant/apres) dans la meme plage
    var direction = null;
    if (event === 'down') {
      direction = 'after';
    } else if (event === 'up') {
      direction = 'before';
    }

    if (direction !== null) {
      $V(alterForm._move, direction);
      $V(alterForm.operation_id, object_id);
      onSubmitFormAjax(alterForm, MultiSalle.reloadOpsPlanning);
    }
  },

  choosePlage: function (operation_id, date, chir_id, plageop_id, salle_id) {
    var query = new Url('bloc', 'ajax_choose_plage');
    query.addParam('operation_id', operation_id);
    query.addParam('date', date);
    query.addParam('chir_id', chir_id);
    query.addParam('plageop_id', plageop_id);
    query.addParam('salle_id', salle_id);
    query.requestModal('40%', '40%');
  },

  editPause: function (operation_id) {
    var query = new Url('bloc', 'ajax_edit_pause');
    query.addParam('operation_id', operation_id);
    query.requestModal('30%', '30%');
  },

  changePlage: function (plageop_id, operation_id) {
    var moveForm = getForm('moveOp');
    $V(moveForm.operation_id, operation_id);
    $V(moveForm.plageop_id, plageop_id);

    var afterMove = function () {
      Control.Modal.close();
      MultiSalle.reloadPlanning();
    };

    onSubmitFormAjax(moveForm, afterMove);
  }
};
