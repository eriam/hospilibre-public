{{*
 * @package Hospilibre\Style\Hospilibre
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
*}}

{{mb_include template=common nodebug=true}}

{{if !$offline && !$dialog}}
<style>
  #main:not(.dialog) > * {
    visibility: hidden;
  }

  #main:not(.dialog) > .Appbar-error {
    visibility: visible;
    position: fixed;
    z-index: 1;
    opacity: 0;
    animation: 120s linear 3s showError;
    font-size: 14px;
  }

  #main:not(.dialog) > .OxVueWrap + div.Appbar-error {
    animation: none;
  }

  #main > .OxVueWrap,
  #main > .OxVueWrap ~ :not(.Appbar-error) {
    visibility: visible;
  }

  @keyframes showError {
    0% {
      opacity: 0;
    }
    1% {
      opacity: 1;
    }
    100% {
      opacity: 1;
    }

  }
</style>
{{/if}}

<script>
  Main.add(
    function () {
      HospilibreExt.initDate("{{$dtnow}}")
    }
  )
</script>

<div id="main" class="{{if $dialog}}dialog{{else}}me-fullpage{{/if}} {{$m}}">
    {{if !$offline && !$dialog}}
      {{mb_entry_point entry_point=$appbar}}

      {{if $showInfoSystem}}
        <div class="Appbar-error">ERREUR : L'Appbar n'est pas compilee. (npm install --no-save && npm run build)</div>
      {{/if}}
      <div class="nav-compenser"></div>
      {{mb_include style=hospilibre_ext template=message nodebug=true update_placeholders=false}}
      {{mb_include style=hospilibre_ext template=offline_mode}}
    {{/if}}


    {{mb_include template=obsolete_module}}
  <div id="systemMsg">
      {{$errorMessage|nl2br|smarty:nodefaults}}
  </div>
