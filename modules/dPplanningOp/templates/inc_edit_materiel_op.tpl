{{*
 * @package Hospilibre\PlanningOp
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
*}}

{{assign var=dmi_active value="dmi"|module_active}}

<script>
  Main.add(function() {
    var form = getForm('editMaterielOp');

    {{if $dmi_active}}
    ProtocoleOp.dmi_active = true;
    {{/if}}

    {{* ⚠ LA GARDE « dPmedicament|module_active » EST INDISPENSABLE, et cet appel n'avait AUCUNE
       condition — pas même une variable. `Hospilibre\Medicament\CMedicament` N'EXISTE PAS dans ce
       dépôt, le module ayant été retiré, et un `static_call` sur une classe absente TUE la requête
       EN SILENCE : aucune erreur PHP n'est journalisée, `CApp::rip()` n'est pas atteint, et
       `checkPeace()` émet « HTTP 500 Application died unexpectedly » SUR UN CORPS DÉJÀ COMPLET. Le
       navigateur jette donc une réponse valide à cause de son statut. C'est le défaut exact de
       l'écran des antécédents, corrigé le 2026-10-06.

       ET LA GARDE EST POSÉE À L'INTÉRIEUR DES APOSTROPHES, pas autour de la ligne : `ProtocoleOp.bdm`
       doit RESTER AFFECTÉE. La supprimer laisserait la propriété à `undefined`, et
       `makeAutocompletesProduit()` juste en dessous s'en sert — on remplacerait un 500 par une
       erreur JavaScript, ce qui n'est pas un progrès. Sans le module, la base documentaire est la
       chaîne vide, qui est l'état « aucune base ». *}}
    ProtocoleOp.bdm = '{{if "dPmedicament"|module_active}}{{'Hospilibre\Medicament\CMedicament::getBase'|static_call:null}}{{/if}}';
    ProtocoleOp.makeAutocompletesProduit(form);

    form.up('div.content').setStyle({overflow: 'visible'});
    form.up('div.modal').setStyle({overflow: 'visible'});
  });
</script>

<form name="editMaterielOp" method="post" class="{{$materiel_op->_spec}}" onsubmit="return ProtocoleOp.onSubmit(this);">
  {{mb_class object=$materiel_op}}
  {{mb_key   object=$materiel_op}}

  {{mb_field object=$materiel_op field=protocole_operatoire_id hidden=true}}

  <table class="form">
    {{mb_include module=system template=inc_form_table_header object=$materiel_op}}

    {{if $dmi_active}}
    <tr>
      <td>
        {{mb_include module=planningOp template=inc_edit_materiel_op_dm}}
      </td>
      <td rowspan="3" style="vertical-align: middle;">
        {{mb_include module=planningOp template=inc_edit_materiel_op_qte}}
      </td>
    </tr>
    <tr>
      <td>
        <div>
          <h2>{{tr}}common-or{{/tr}}</h2>
        </div>
      </td>
    </tr>
    <tr>
      <td>
        {{mb_include module=planningOp template=inc_edit_materiel_op_produit}}
      </td>
    </tr>
      <tr>
        <td>
            {{mb_field object=$materiel_op field=completude_panier form=editMaterielOp typeEnum=checkbox}}
            {{mb_label object=$materiel_op field=completude_panier form=editMaterielOp typeEnum=checkbox}}
        </td>
      </tr>
    {{else}}
    <tr>
      <td>
        {{mb_include module=planningOp template=inc_edit_materiel_op_produit}}
      </td>
      <td>
        {{mb_include module=planningOp template=inc_edit_materiel_op_qte}}
      </td>
    </tr>
      <tr>
        <td>
            {{mb_field object=$materiel_op field=completude_panier form=editMaterielOp typeEnum=checkbox}}
            {{mb_label object=$materiel_op field=completude_panier form=editMaterielOp typeEnum=checkbox}}
        </td>
      </tr>
    {{/if}}

    {{mb_include module=system template=inc_form_table_footer object=$materiel_op
                 options="{typeName: \$T('CProtocoleOperatoire'), objName: '`$materiel_op->_view`'}" options_ajax=Control.Modal.close}}
  </table>
</form>
