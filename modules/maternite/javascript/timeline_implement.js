/**
 * @package Hospilibre\Addictologie
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */
TimelineImplement = {
    pregnancy_id: 0,

    /**
     * Recharge la timeline en tenant compte du filtre de menus.
     *
     * @param menus        menus retenus pour le filtrage
     * @param pregnancy_id identifiant de la grossesse (facultatif)
     */
    refreshResume: function (menus, pregnancy_id) {
        var targetPregnancy = pregnancy_id ? pregnancy_id : TimelineImplement.pregnancy_id;

        new Url('maternite', 'ajax_timeline_pregnancy')
            .addParam('menu_filter', JSON.stringify(menus), true)
            .addParam('pregnancy_id', targetPregnancy)
            .addParam('refresh', 1)
            .requestUpdate('pregnancy_main_timeline');
    },

    /**
     * Applique un filtre par praticien sur la timeline.
     *
     * @param pregnancy_id   identifiant de la grossesse
     * @param menu_filter    menus sélectionnés
     * @param filter_user_id praticien choisi pour filtrer la timeline
     */
    selectPractitioner: function (pregnancy_id, menu_filter, filter_user_id) {
        new Url('maternite', 'ajax_timeline_pregnancy')
            .addParam('pregnancy_id', pregnancy_id)
            .addParam('menus_filter', menu_filter)
            .addParam('practitioner_filter', filter_user_id)
            .addParam('refresh', 1)
            .requestUpdate('pregnancy_main_timeline');
    },

    /**
     * Ouvre l'impression de la fiche de grossesse.
     *
     * @param {int} anesth_file identifiant du dossier d'anesthésie
     */
    printFichePregnancy: function (anesth_file) {
        new Url('cabinet', 'print_fiche')
            .addParam('dossier_anesth_id', anesth_file)
            .addParam('print', true)
            .popup(700, 500, 'printFiche');
    }
};
