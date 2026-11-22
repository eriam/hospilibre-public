{{*
 * @package Hospilibre\Style\Hospilibre
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
*}}

{{mb_default var=width  value=""}}
{{mb_default var=height value=""}}
{{mb_default var=alt    value=""}}
{{mb_default var=title  value=""}}
{{mb_default var=class  value=""}}
{{mb_default var=id     value=""}}

{{* Logo par défaut de l'application. mb.png — le logo OX MediBoard de l'amont — reste
   dans le dépôt mais n'est plus référencé : Hospilibre est un fork, et sa page de
   connexion affichait encore la marque d'origine. La surcharge par établissement
   (images/pictures/logo_custom.svg) est inchangée. *}}
{{assign var=logo        value="./style/hospilibre_ext/images/pictures/hospilibre.svg"}}
{{assign var=logo_custom value="images/pictures/logo_custom.svg"}}

{{if !is_file($logo_custom)}}
  {{assign var=logo_custom value="images/pictures/logo_custom.png"}}
{{/if}}

{{if is_file($logo_custom)}}
  {{assign var=logo value=$logo_custom}}
{{/if}}
{{assign var=homepage value="-"|explode:$app->user_prefs.DEFMODULE}}

{{if $app->user_id}}
  {{assign var=href value="?m=`$homepage.0`"}}
  {{if $homepage|@count == 2}}
    {{assign var=href value="`$href`&tab=`$homepage.1`"}}
  {{/if}}
{{else}}
  {{assign var=href value=$conf.system.website_url}}
{{/if}}

<img src="{{$logo}}"
  {{if @$width}}width="{{$width}}"{{/if}} 
  {{if @$height}}height="{{$height}}"{{/if}}
  {{if @$alt}}alt="{{$alt}}"{{/if}}
  {{if @$title}}title="{{$title}}"{{/if}}
  {{if @$class}}class="{{$class}}"{{/if}}
  {{if @$id}}id="{{$id}}"{{/if}} />
