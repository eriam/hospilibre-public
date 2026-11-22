/**
 * @package Hospilibre\Board
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

Board = {
  onSelectFilter: function(field) {
    if (field.name == 'praticien_id') {
      if (field.form.elements['function_id']) {
        $V(field.form.elements['_function_view'], '', false);
        $V(field.form.elements['function_id'], 0, false);
      }
    }
    else {
      $V(field.form.elements['_chir_view'], '', false);
      $V(field.form.elements['praticien_id'], 0, false);
    }

    field.form.submit();
  },
  /**
   * Remplis les champ begin_date et end_date pour le filtre sur les interventions non cotées (saisie des codages)
   *
   * @param period_start
   * @param period_end
   */
  setPeriod: function (period_start, period_end) {
    var form = getForm('filterObjects'),
      debut = form.begin_date,
      debut_da = form.begin_date_da,
      fin = form.end_date,
      fin_da = form.end_date_da;
    // On n'utilise pas $V() sur 'debut' et 'fin' pour ne pas déclencher l'event "onchange"
    debut.value = period_start;
    fin.value = period_end;
    $V(debut_da, Date.fromDATE(period_start).toLocaleDate());
    $V(fin_da, Date.fromDATE(period_end).toLocaleDate());
  },
  /**
   *  Période personnalisée pour les filtres des dates
   */
  customPeriod: function (debutChanged) {
    var form = getForm('filterObjects'),
      debut_da = form.begin_date_da,
      fin_da = form.end_date_da,
      debut = form.begin_date,
      fin = form.end_date;
    // Décoche les cases de filtres prédéfinis
    form.select_days[0].checked = false;
    form.select_days[1].checked = false;
    form.select_days[2].checked = false;
    form.select_days[3].checked = false;
    // On vérifie que le début est plus grand que la fin
    if (debut.value < fin.value) {
      return;
    }
    // Sinon la plus grande valeur est utilisée dans les deux champs
    if (debutChanged) {
      fin.value = debut.value;
      fin_da.value = Date.fromDATE(fin.value).toLocaleDate();
    } else {
      debut.value = fin.value;
      debut_da.value = Date.fromDATE(debut.value).toLocaleDate();
    }
  },

  updateDocuments: function(user_id,form) {
    if (form){
      new Url('board', 'ajax_list_documents')
        .addParam('chir_id', user_id)
        .addParam('statut',form.statut.value)
        .addParam('vue_praticien',form.vue_praticien.value)
        .requestUpdate('documents');
    }
  },

  /**
   * @param compte_rendu_id
   */
  askCorrection:   function (compte_rendu_id) {
    new Url('board', 'askCorrection')
      .addParam('compte_rendu_id', compte_rendu_id)
      .requestModal('25%');
  },
  /**
   * Rafraichit la liste des documents pour le tdb secrétaire
   * @param id
   * @param date_min
   */
  reloadDocuments: function (id, form) {
    new Url('board', 'getListDocuments')
      .addParam('chir_ids[]', id, true)
      .addParam('date_min', form._date_min.value)
      .requestUpdate('refresh_list_documents')
  },

  selectPraticien: function (element, date) {
    new Url("mediusers", "ajax_users_autocomplete")
      .addParam("praticiens", "1")
      .addParam("input_field", element.name)
      .autoComplete(element, null, {
        minChars:           0,
        method:             "get",
        select:             "view",
        dropdown:           true,
        afterUpdateElement: function (field, selected) {
          var span = selected.down('.view');
          $V(element, span.getText());
          var id = selected.getAttribute("id").split("-")[2];
          let container = document.getElementById("container_praticiens");
          let praticiens = [];
          let children = container.children;
          for (let i = 0; i < children.length; i++) {
            praticiens.push(children[i].value);
          }
          praticiens.push(id);
          new Url("board", "tdbSecretaire")
            .addParam('chir_ids[]', praticiens, true)
            .addParam('date_min', date.value)
            .requestUpdate("reload_tdb");
        }
      });
  },
  /**
   * Permet de supprimer un praticien du filtre
   * @param id
   * @param praticiens
   */
  removePraticien: function (id, praticiens, form) {

    let elt = document.getElementById(id);
    let index = praticiens.indexOf(parseInt(elt.value));
    if (index > -1) {
      praticiens.splice(index, 1);
    }
    elt.remove();

    if (!praticiens.length) {
      praticiens = -1;
    }
    new Url("board", "tdbSecretaire")
      .addParam('chir_ids[]', praticiens, true)
      .addParam('date_min', form._date_min.value)
      .requestUpdate("reload_tdb");
  },
  /**
   * Affiche les prescriptions du praticien sélectionné
   * @param chirSel
   * @param date
   * @param function_id
   */
  showPrescriptions: function (chirSel, date, function_id){
    new Url('board', 'ajax_tabs_prescription')
      .addParam('chirSel' , chirSel)
      .addParam('date', date)
      .addParam('function_id', function_id)
      .requestUpdate('prescriptions');
  },
  /**
   * Affiche les actes non côtés du praticien
   * @param praticien_id
   * @param end_date
   * @param board
   * @param frequency
   */
  initUpdateActes: function (praticien_id, end_date, board, frequency){
    new Url('board', 'ajax_list_interv_non_cotees')
      .addParam('praticien_id', praticien_id)
      .addParam('end_date', end_date)
      .addParam('board', board)
      .periodicalUpdate('actes_non_cotes', {frequency: frequency});
  },
  /**
   * Affiche la messagerie
   * @param account_id
   * @param mode
   * @param frequency
   */
  updateMessagerie: function (account_id, mode, frequency){
    new Url('messagerie', 'ajax_list_mails')
      .addParam('account_id', account_id)
      .addParam('mode', mode)
      .periodicalUpdate('messagerie', {
        frequency: frequency,
        method: 'get',
        onComplete: function() {
          if ($$('#messagerie tr').length <= 2) {
            $('tab_messagerie').addClassName('empty');
          }
        }
      });
  },
  /**
   * Affiche les relances périodiquement
   * @param chir_id
   * @param function_id
   * @param frequency
   */
  initUpdateRelances: function (chir_id, function_id, frequency){
    new Url('pmsi', 'ajax_vw_relances')
      .addParam('chir_id', chir_id)
      .addParam('function_id', function_id)
      .periodicalUpdate('relances', {frequency: frequency});
  },
  /**
   * Affiche les documents périodiquement
   * @param chir_id
   * @param function_id
   * @param frequency
   */
  initUpdateDocuments: function(chir_id, function_id, frequency) {
    new Url('board', 'ajax_list_documents')
      .addParam('chir_id', chir_id)
      .addParam('function_id', function_id)
      .periodicalUpdate('documents', {frequency: frequency});
  },
  /**
   * Affiche les consultations du praticien
   * @param chirSel
   * @param functionSel
   * @param date
   * @param vue2
   * @param selConsult
   * @param board
   * @param frequency
   */
  initUpdateListConsults: function (chirSel, functionSel, date, vue2, selConsult, board, frequency) {
    new Url('cabinet', 'httpreq_vw_list_consult')
      .addParam("chirSel", chirSel)
      .addParam("functionSel", functionSel)
      .addParam("date", date)
      .addParam("vue2", vue2)
      .addParam("selConsult", selConsult)
      .addParam("board", board)
      .periodicalUpdate("tab-consultations", {frequency: frequency});

  },
  /**
   * Affiche les prescriptions du praticien
   * @param pratSel
   * @param functionSel
   * @param date
   * @param board
   * @param frequency
   */
  initUpdateListPrescriptions: function (pratSel, functionSel, date, board , frequency) {
    new Url('board', 'sejoursOtherResponsable')
      .addParam('pratSel', pratSel)
      .addParam('functionSel', functionSel)
      .addParam('date', date)
      .addParam('board', board)
      .periodicalUpdate("tab-autre-responsable", {frequency: frequency});
  },
  /**
   * Affiche les opérations
   * @param pratSel
   * @param functionSel
   * @param date
   * @param urgences
   * @param board
   * @param frequency
   */
  initUpdateListOperations: function (pratSel, functionSel, date, urgences, board, frequency) {
    new Url('planningOp', 'httpreq_vw_list_operations')
      .addParam('pratSel'    , pratSel)
      .addParam('functionSel', functionSel)
      .addParam('date'       , date)
      .addParam('urgences'   , urgences)
      .addParam('board'      , board)
      .periodicalUpdate("tab-operations", {frequency: frequency});
  },
  /**
   * Affiche les sejours
   * @param chirSel
   * @param functionSel
   * @param date
   */
  updateListHospi: function (chirSel, functionSel, date){
    new Url('board', 'httpreq_vw_hospi')
      .addParam('chirSel'    , chirSel)
      .addParam('functionSel', functionSel)
      .addParam('date'       , date)
      .requestUpdate('tab-hospitalisations');
  },
  /**
   * Affiche la liste des opérations annulées
   * @param chirSel
   * @param functionSel
   * @param date
   */
  updateCanceledSurgeries: function (chirSel, functionSel, date){
    new Url('board', 'ajax_list_canceled_surgeries')
      .addParam('practitioner_id', chirSel)
      .addParam('function_id', functionSel)
      .addParam('date', date)
      .requestUpdate('tab-canceled-operations');
  },
  /**
   * Reactualise le sejour
   * @param sejour_id
   * @param listView
   * @param service_id
   * @param show_affectation
   * @param show_full_affectation
   * @param board
   * @param module_active
   */
  refreshLineSejour: function (sejour_id, listView, service_id, show_affectation, show_full_affectation, board, module_active) {
    new Url('soins', 'vwSejours')
      .addParam('sejour_id', sejour_id)
      .addParam('lite_view', listView)
      .addParam('service_id', service_id)
      .addParam('show_affectation', show_affectation)
      .addParam('show_full_affectation', show_full_affectation)
      .addParam('board', board)
      .requestUpdate('line_sejour_' + sejour_id, {
        onComplete: function () {
          if (module_active) {
            ImedsResultsWatcher.loadResults();
          }
        }
      });
  }
};
