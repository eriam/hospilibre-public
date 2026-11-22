{{*
 * @package Hospilibre\System
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
*}}

{{assign var=tpl value='config/custom/'|cat:$_prop.tpl}}

{{* ⚠ LE GABARIT DÉCLARÉ PEUT NE PAS EXISTER, ET SON ABSENCE CASSAIT L'ÉCRAN. Mesuré le
   2026-10-07 : trois spécifications de modules PRÉSENTS réclament un gabarit supprimé par
   `43d5c37b7` (« retrait en masse de 6 fichiers OXOL du module system ») —

     dPcabinet/CConfigurationCabinet.php:107    -> inc_config_category_summary
     dPsalleOp/CConfigurationSalleOp.php:51     -> inc_config_category_summary
     fhir/CConfigurationFHIR.php:33             -> inc_config_fhir_list_object_delegated

   `mb_include` sans `ignore_errors` demande à Smarty d'inclure un fichier qui n'est pas là.
   C'est la même famille que les `static_call` sur classe absente : le RETRAIT a laissé ses
   APPELANTS. Le filet de non-régression de ce retrait était vert — il comptait les références
   pendantes de classes et de gabarits, mais une référence écrite en CHAÎNE dans une spécification
   (« custom tpl|… ») lui échappait.

   ⚠ ET LE REPLI N'EST PAS UN `ignore_errors`, qui n'afficherait RIEN. Un champ de configuration
   absent du formulaire, c'est `c[...]` absent de la requête d'enregistrement — donc la valeur
   stockée potentiellement EFFACÉE au premier « Enregistrer ». On remplacerait un écran cassé par
   une perte silencieuse de configuration, ce qui est pire. Le repli rend donc un champ texte
   PORTANT LE BON NOM et la valeur courante : l'écran s'ouvre, la valeur se conserve, et elle
   reste modifiable en clair faute de l'interface spécialisée. *}}
{{if $tpl|tpl_exist:'system'}}
  {{mb_include module=system template=$tpl}}
{{else}}
  {{if $is_last}}
    <input type="text" name="c[{{$_feature}}]" value="{{$value}}" size="20"
           {{if $is_inherited}}disabled{{/if}} />
    <em style="color: #880">&mdash; interface spécialisée absente ({{$_prop.tpl}}), valeur en clair</em>
  {{else}}
    {{$value}}
  {{/if}}
{{/if}}
