{{*
 * @package Hospilibre\Developpement
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
*}}

<script>
  function filterError() {
    let type = document.querySelector('input[name="source_type"]:checked').value;
    let url = null;
    if (type === "elastic") {
      url = new Url("developpement", "listErrorLogsElastic");
    } else {
      url = new Url("developpement", "listErrorLogs");
    }
    url.addFormData(getForm("filter-error"));
    url.requestUpdate("error-list");
    return false;
  }

  function filterLog() {
    document.body.style.cursor = "wait";

    const waring_file = document.getElementById("div-warning-local-logging-file");
    const file_info = document.getElementById("application-log-file-info");
    const elastic_info = document.getElementById("application-log-elastic-info");

    var url = "";
    if (document.getElementById("radio_btn_elasticsearch").checked) {
      url = new Url("developpement", "listApplicationLogUsingElastic");
      waring_file.style.display = "none";
      if (file_info) {
        file_info.style.display = "none";
      }
      if (elastic_info) {
        elastic_info.style.display = "block";
      }
    } else {
      url = new Url("developpement", "listApplicationLogUsingFile");
      document.getElementById("div-warning-local-logging-file").style.display = "block";
      if (file_info) {
        file_info.style.display = "block";
      }
      if (elastic_info) {
        elastic_info.style.display = "none";
      }
    }
    url.addFormData(getForm("filter-log"));
    url.requestHTML(function (html) {
      var parent = document.getElementById('log-list');
      var divs = document.getElementsByClassName('divShowMoreLog');
      for (var pas = 0; pas < divs.length; pas++) {
        divs[pas].remove();
      }
      parent.insert(html);
      document.body.style.cursor = "auto";
    });

    var log_start = parseInt(document.getElementById('log_start').value) + 1000;
    document.getElementById('log_start').value = log_start;

    return false;
  }

  function showMoreLog(element) {
    var i = element.querySelector('i');
    var class_list = i.classList;
    class_list.remove('fa-arrow-circle-down');
    class_list.add('fa-spinner');
    class_list.add('fa-spin');
    filterLog();
  }

  function refreshLog() {
    document.getElementById('log-list').innerHTML = '';
    document.getElementById('log_start').value = 0;
      {{if $enable_grep}}
    document.getElementById('grep_search').value = '';
      {{/if}}

    filterLog();
  }

  function grepLog() {
    var grep_len = document.getElementById('grep_search').value.length;

    if (grep_len > 0 && grep_len < 3) {
      alert('La recherche doit dépasser 3 caractères.');
      return false;
    }

    document.getElementById('log-list').innerHTML = '';
    document.getElementById('log_start').value = 0;
    filterLog();
    return false;
  }

  function changePage(start) {
    var form = getForm("filter-error");
    $V(form.start, start);
    form.onsubmit();
  }


  function toggleCheckboxes(checkbox) {
    var form = getForm("filter-error");

    checkbox.next('fieldset').select('input.type').invoke("writeAttribute", "checked", checkbox.checked);

    $V(form.start, 0);
  }

  function removeLogs() {
    if (document.getElementById("radio_btn_elasticsearch").checked) {
      if (confirm('Voulez-vous vider complètement l\'index Elasticseach de log ?')) {
        new Url("developpement", "deleteApplicationLogElasticsearchIndex")
          .requestUpdate('log-list', function () {
            updateFilter();
          });
      }
    } else {
      if (confirm('Voulez-vous vider complètement le journal de log ?')) {
        new Url("developpement", "deleteApplicationLogFile")
          .requestUpdate('log-list', function () {
            updateFilter();
          });
      }
    }
  }

  function listErrorLogBuffer() {
    new Url("developpement", "ajax_list_error_log_buffer")
      .requestModal(800, 600);
  }

  function listErrorLogWhitelist() {
    new Url("developpement", "ajax_list_error_log_whitelist")
      .requestModal(800, 600);
  }

  function toogleErrorLogWhitelist(error_log_id) {
    let type = document.querySelector('input[name="source_type"]:checked').value;

    new Url("developpement", "ajax_toogle_error_log_whitelist")
      .addParam('error_log_id', error_log_id)
      .addParam('is_elastic_log', type === "elastic")
      .requestUpdate('systemMsg', {
        onComplete: function () {
          document.getElementById("btn-search-errors").click();
        }
      });
  }

  function download() {
    var url = new Url('developpement', 'downloadLogFile', 'raw');
    url.addFormData(getForm("filter-log"));
    url.open("");
  }

  function updateFilter() {
    /*var elements = getForm('filter-log').filter;
     $A(elements).each(function (e) {
       $("filter-log").select(e.value).invoke('setVisible', e.checked);
     });
     new CookieJar().put("filter-log", $V(elements));
     */
  }

  function jsonViewer(infos) {
    new Url("developpement", "showLogInfos")
      .addParam('json', infos)
      .requestModal(800, 500, {
        method:        'post',
        showReload:    false,
        getParameters: {m: 'developpement', a: 'showLogInfos'}
      });
  }

  Main.add(function () {
    Control.Tabs.create("error-log-tabs", true);
    filterError();
    filterLog();

    ViewPort.SetAvlHeight(document.getElementById('log-list'), 1);
  });
</script>

<style>
  .error-warning {
    background-color: rgba(255, 205, 117, 0.6) !important;
  }

  .error-error {
    background-color: rgba(255, 153, 153, 0.6) !important;
  }

  .error-notice {
    background-color: rgba(204, 204, 255, 0.6) !important;
  }

  .divInfosLog {
    text-align: center;
    margin: 10px;
    color: #808080;
    font-size: 12px;
    font-family: Tahoma, Verdana, Arial, Helvetica, sans-serif;
  }

  .table_log {
    width: 100%;
    border-spacing: 5px;
  }

  .tr_log {
    cursor: pointer;
  }

  .tr_log:hover {
    font-weight: bolder;
    background-color: #f1f1f1;
  }

  .divShowMoreLog {
    width: 99%;
    margin-top: 10px;
    margin-bottom: 10px;
    padding: 5px;
    font-size: 18px;
    text-align: center;
    vertical-align: middle;
    border-radius: 5px;
    font-family: Tahoma, Verdana, Arial, Helvetica, sans-serif;
    background-color: #c1c1c1;
    color: #555;
  }

  .divShowMoreLog:hover {
    background-color: #A6A6A6;
    color: #111;
    cursor: pointer;
  }

  #log-list {
    font-family: "Courier New";
    overflow-y: auto;
    overflow-x: hidden;
    display: block;
  }

  #log-tab {
    padding-top: 5px;
  }
</style>

<ul id="error-log-tabs" class="control_tabs">
  <li><a href="#error-tab">{{tr}}Error{{/tr}}</a></li>
  <li><a href="#log-tab">{{tr}}Hospilibre{{/tr}}
      <small>({{$log_size}})</small>
    </a></li>
</ul>

<div id="error-tab">
  <form name="filter-error" action="" method="get" onsubmit="return filterError();">
    <input type="hidden" name="start" value="0"/>

    <table class="layout">
      <tr>
        <td>
          <table class="main form">
            <tr>
              <th>{{mb_label object=$error_log field=text}}</th>
              <td>{{mb_field object=$error_log field=text prop=str}}</td>

              <th>{{mb_label object=$error_log field=_datetime_min}}</th>
              <td>{{mb_field object=$error_log field=_datetime_min register=true form="filter-error"}}</td>

              <th>{{tr}}User{{/tr}}</th>
              <td>
                <select name="user_id" class="ref" style="max-width: 14em;">
                  <option value="">&mdash; Tous les utilisateurs</option>
                    {{foreach from=$list_users item=_user}}
                      <option value="{{$_user->user_id}}" {{if $_user->user_id == $user_id}}selected{{/if}}>
                          {{$_user}}
                      </option>
                    {{/foreach}}
                </select>
              </td>
            </tr>
            <tr>
              <th>{{mb_label object=$error_log field=server_ip}}</th>
              <td>{{mb_field object=$error_log field=server_ip}}</td>

              <th>{{mb_label object=$error_log field=_datetime_max}}</th>
              <td>{{mb_field object=$error_log field=_datetime_max register=true form="filter-error"}}</td>

              <th>{{tr}}Type{{/tr}}</th>
              <td>
                <label>
                  <input type="checkbox" name="human" value="1" {{if $human}}checked{{/if}} />
                    {{tr}}Humans{{/tr}}
                </label>
                <label>
                  <input type="checkbox" name="robot" value="1" {{if $robot}}checked{{/if}} />
                    {{tr}}Robots{{/tr}}
                </label>
              </td>
            </tr>
            <tr>
              <th>Groupement</th>
              <td>
                <select name="group_similar" onchange="$V(form.start, 0);">
                  <option value="similar" {{if $group_similar == 'similar'}} selected{{/if}}>Grouper les similaires
                  </option>
                  <option value="signature" {{if $group_similar == 'signature'}}selected{{/if}}>Grouper par signature
                  </option>
                  <option value="no" {{if $group_similar == 'no'}} selected{{/if}}>Ne pas grouper</option>
                </select>
              </td>

              <th>Trier par</th>
              <td>
                <select name="order_by">
                  <option
                    value="date" {{if $order_by == "date"}} selected {{/if}}>{{tr}}CErrorLog-datetime{{/tr}}</option>
                  <option
                    value="quantity" {{if $order_by == "quantity"}} selected {{/if}}>{{tr}}CErrorLog-_quantity{{/tr}}</option>
                </select>
              </td>
                {{if "elastic"|array_key_exists:$conf and "error-log"|array_key_exists:$conf.elastic}}
                    {{assign var=error_log_datasource value=true}}
                {{else}}
                    {{assign var=error_log_datasource value=false}}
                {{/if}}
              <th {{if !$error_log_datasource}}style="display: none"{{/if}}>Source</th>
              <td {{if !$error_log_datasource}}style="display: none"{{/if}}>
                <label>
                  <input type="radio" name="source_type" value="sql"
                         {{if !$elastic_up || !$conf.error_log_using_nosql || !$error_log_datasource}}checked{{/if}}/>
                    {{tr}}common-Database{{/tr}}
                </label>
                <label>
                  <input type="radio" name="source_type" value="elastic"
                         {{if $elastic_up && $conf.error_log_using_nosql && $error_log_datasource}}checked{{/if}}/>
                    {{tr}}common-Elasticsearch{{/tr}}
                </label>
              </td>
            </tr>
            <tr>
              <th>Type d'erreur</th>
              <td class="text" colspan="5">
                <span>
                {{foreach from=$error_types key=_cat item=_types}}
                  <span style="display: inline; white-space: nowrap;">
                    <input type="checkbox" onclick="toggleCheckboxes(this);"
                           style="margin-right: -3px; margin-top: 4px; vertical-align: top;"/>
                    <fieldset style="display: inline-block" class="error-{{$_cat}} me-padding-2">
                      {{foreach from=$_types item=_type}}
                        <label>
                          <input type="checkbox" class="type" name="error_type[{{$_type}}]" value="1"
                                  {{if array_key_exists($_type,$error_type)}} checked {{/if}}
                                 onclick="$V(this.form.start, 0);"/>
                            {{tr}}CErrorLog.error_type.{{$_type}}{{/tr}}
                        </label>
                      {{/foreach}}
                    </fieldset>
                  </span>
                {{/foreach}}
              </td>
            </tr>
            <tr>
              <th></th>
              <td>
                <button type="submit" class="search" id="btn-search-errors">{{tr}}Filter{{/tr}}</button>
                <button type="button" class="close" onclick="this.form.clear();">{{tr}}Reset{{/tr}}</button>
              </td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
  </form>
    {{if $count_error_log_buffer > 0 }}
      <div id="logs-buffer" class="small-warning">
        <a onclick="listErrorLogBuffer(this)" style="cursor: pointer;">
          <b>{{$count_error_log_buffer}}</b> {{tr}}CErrorLog.error_log_buffer_file{{/tr}}
        </a>
      </div>
    {{/if}}
  <div id="error-list"></div>
</div>

<div id="log-tab">

  <table class="layout" style="width:100%;">
    <tr class="main form">
      <td style="border: 1px solid grey">
        <!-- FORM -->
        <form name="filter-log" method="get" onsubmit="return grepLog();">
          <button class="trash" type="button"
                  onclick="removeLogs()">
              {{tr}}Reset{{/tr}}
          </button>

          <button class="change singleclick" type="button" onclick="refreshLog()">
              {{tr}}Refresh{{/tr}}
          </button>

          <button class="change download" type="button" onclick="download()">
              {{tr}}Download{{/tr}}
          </button>

          <script>
            Main.add(function () {
              var values = new CookieJar().get("grep_search");
              $V(getForm("grep_search"), values);
              updateFilter();
            });
          </script>

          <input type="hidden" name="log_start" id="log_start" value="0">
            {{if $enable_grep}}
              <div style="display: inline-block;">
                <input type="text" name="grep_search" id="grep_search" placeholder="Filtrer les logs ..."
                       style="width:250px;"
                       title="Default pattern is multi key words">
                <label><input type="checkbox" id="grep_regex" name="grep_regex" value="1"> Regex</label>
                <label><input type="checkbox" id="grep_sensitive" name="grep_sensitive" value="1"> Match Case</label>

                  {{if "elastic"|array_key_exists:$conf and "application-log"|array_key_exists:$conf.elastic}}
                      {{assign var=application_log_datasource value=true}}
                  {{else}}
                      {{assign var=application_log_datasource value=false}}
                  {{/if}}
                <label {{if !$application_log_datasource}}hidden{{/if}}>
                  <input type="radio"
                         name="elasticsearch_or_file"
                         value="file"
                         id="radio_btn_file"
                          {{if !$elastic_up || !$conf.application_log_using_nosql || !$application_log_datasource}} checked {{/if}}
                  />
                  File
                </label>
                <label {{if !$application_log_datasource}}hidden{{/if}}>
                  <input type="radio"
                         name="elasticsearch_or_file"
                         value="elasticsearch"
                         id="radio_btn_elasticsearch"
                          {{if $elastic_up && $conf.application_log_using_nosql && $application_log_datasource}} checked {{/if}}
                  />
                  Elasticsearch
                </label>
                <button type="submit" class="search">{{tr}}Filter{{/tr}}</button>
              </div>
            {{/if}}
          <br>
        </form>

        <div class="small-warning" id="div-warning-local-logging-file" style="display: none;">
          <p>{{tr}}SystemLoggingController-warning-Local log file{{/tr}}</p>
        </div>
          {{if $log_size > 0}}
            <div class="small-info" id="application-log-file-info" style="display: none;">
              <b>{{tr}}File{{/tr}} :</b> {{$log_file_path}}
              <b>{{tr}}ApplicationLog-First Log{{/tr}} : </b>{{$first_log_date|date_format:$conf.datetime}}
              <b>{{tr}}ApplicationLog-Last Log{{/tr}} : </b>{{$last_log_date|date_format:$conf.datetime}}
            </div>
          {{/if}}
          {{if $elastic_log_size > 0}}
            <div class="small-info" id="application-log-elastic-info" style="display: none;">
              <b>{{tr}}Indexes{{/tr}} : </b> {{$index}}
              <b>{{tr}}ApplicationLog-First log{{/tr}} : </b>{{$elastic_first_log_date|date_format:$conf.datetime}}
              <b>{{tr}}ApplicationLog-Last log{{/tr}} : </b>{{$elastic_last_log_date|date_format:$conf.datetime}}
              <b>{{tr}}ApplicationLog-Log number{{/tr}} : </b>{{$elastic_log_size}}
            </div>
          {{/if}}
      </td>
    </tr>

    <tr>
      <td>
        <!-- RESULT -->
        <div id="log-list" class="overflow y-scroll"></div>
      </td>
    </tr>
  </table>
</div>
