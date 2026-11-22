<!--
  @package Openxtrem\Core
  @author  SAS OpenXtrem <dev@openxtrem.com>
  @license https://www.gnu.org/licenses/gpl.html GNU General Public License
  @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
-->

<template>
  <div class="Appbar">
    <a
      class="Appbar-logo"
      :href="homeLink"
    >
      <!--
        Logo Hospilibre : croix de soins en cinq pièces détachées — le signe médical, et
        l'assemblage libre. Reprend style/hospilibre_ext/images/icons/logo.svg.

        LES COULEURS RESTENT AU THÈME, et c'est délibéré : le fichier source les code en dur
        (#3F51B5 et #0288D1) mais ce sont exactement $primary et $secondary de utils.scss. On
        garde donc les classes `Appbar-logoPart up|bottom`, que Appbar.scss colore déjà, plutôt
        que de figer des valeurs ici. Un thème qui change ses couleurs emporte le logo avec lui,
        au lieu de laisser une croix bleue sur une barre repeinte.

        Les quatre branches prennent `up` (primary), le cœur prend `bottom` (secondary) — même
        répartition que l'ancien logo en deux parties, donc rien à changer dans la feuille de style.
      -->
      <svg
        v-if="!isTamm"
        class="Appbar-logoContent" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg"
        role="img" aria-label="Hospilibre">
        <title>Hospilibre</title>
        <!--
          stroke-width="0" : Appbar.scss pose `stroke: $primary` sur `.up`, hérité de l'ancien
          logo qui était tracé. Sans cette annulation le trait rend à 1 unité, soit 0,5 de chaque
          côté — ce qui ramènerait l'écart entre deux pièces de 1,8 à 0,8 unité et effacerait de
          moitié le détachement, qui EST la marque.
        -->
        <rect x="14" y="0" width="12" height="12.2" rx="3" stroke-width="0" class="Appbar-logoPart up"/>
        <rect x="14" y="27.8" width="12" height="12.2" rx="3" stroke-width="0" class="Appbar-logoPart up"/>
        <rect x="0" y="14" width="12.2" height="12" rx="3" stroke-width="0" class="Appbar-logoPart up"/>
        <rect x="27.8" y="14" width="12.2" height="12" rx="3" stroke-width="0" class="Appbar-logoPart up"/>
        <rect x="14" y="14" width="12" height="12" rx="3" stroke-width="0" class="Appbar-logoPart bottom"/>
      </svg>
      <svg
        v-else
        class="Appbar-logoContent"
        xmlns="http://www.w3.org/2000/svg" viewBox="0 0 9.525 9.525">
        <path d="M5.316 1.834c-.819.002-1.374.365-1.697.729-.201.226-.21.316-.274.459-.285-.176-.582-.258-.819-.237-.314.029-.547.153-.547.153l-.004.002-.004.002c-.415.235-.601.591-.66.873-.031.145-.01.17-.002.264-.767.235-1.146.758-1.257 1.229a2.15 2.15 0 0 0 .006.966l.004.015c.209.626.61.976.955 1.152s.643.188.649.189c.096.017 2.243.379 3.662-1.309a.86.86 0 0 0 .289.054.9.9 0 0 0 .896-.896.9.9 0 0 0-.896-.894.9.9 0 0 0-.894.894c0 .197.067.377.176.526-1.259 1.464-3.169 1.1-3.169 1.1l-.019-.004h-.019s-.189-.006-.434-.132-.522-.354-.687-.84c-.002-.008-.086-.339-.002-.7.086-.367.295-.742 1.051-.899l.266-.055-.061-.264s-.027-.117.008-.285.119-.356.398-.517c.002-.002.14-.073.341-.092a.76.76 0 0 1 .668.251l.312.325.134-.431s.082-.266.329-.543.635-.551 1.303-.551h.011s.362-.01.718.174.706.519.706 1.378l.01.197.021.025c.061.067.153.109.251.103a.32.32 0 0 0 .243-.13l.019-.027-.023-.331-.002-.031c-.059-.897-.52-1.418-.982-1.655-.486-.256-.962-.237-.972-.237zm2.572 2.174l-.031.013c-.084.04-.151.119-.174.216s.002.195.057.27l.021.027.262.059.01.002.042.008.029.006.015.004a1.19 1.19 0 0 1 .498.266c.21.193.375.471.375.873v.019s.021.364-.115.712-.364.652-1.031.658l-2.704.019c-.23.182-.513.365-.922.532l3.624-.019c.859-.004 1.338-.517 1.527-.995.184-.467.153-.905.151-.928-.002-.551-.245-.984-.547-1.261-.233-.214-.482-.348-.729-.398l-.01-.002zM5.64 5.081a.38.38 0 0 1 .385.385.38.38 0 0 1-.385.385.38.38 0 0 1-.385-.385.38.38 0 0 1 .385-.385z" stroke-width=".019135" class="Appbar-logoPart bottom"/>
      </svg>
      <div
        v-if="isQualif"
        class="Appbar-qualif"
      >
        Qualif
      </div>
    </a>
    <div class="Appbar-navigation">
      <div v-click-outside="clickOutside">
        <div
          class="Appbar-module"
          :class="moduleClass"
          @click="displayNavModule"
        >
          <ox-icon
            class="Appbar-moduleIcon"
            icon="module"
            :size="24"
          />
          <div
            v-if="isM"
            class="Appbar-moduleName"
          >
            {{ tr("module-" + module.mod_name + "-court") }}
          </div>
        </div>
        <div
          v-if="!isTamm && showNavModule"
          class="Appbar-navModule"
        >
          <keep-alive>
            <nav-modules
              ref="navModule"
              :default-modules-data="defaultModules"
              :home-link="homeLink"
              :mobile="!isM"
              :show-home-link="!isXL"
              :tab-shortcuts="tabShortcutsData"
            />
          </keep-alive>
        </div>
        <div
          v-else-if="isTamm && showNavModule"
          class="Appbar-navModuleTamm"
        >
          <keep-alive>
            <nav-modules-tamm
              v-if="showNavModule"
              :can-see-modules="userInfo._is_admin"
              :default-modules-data="defaultModules"
              :home-link="userInfo._links.default"
              :mobile="!isM"
              ref="navModuleTamm"
              :tamm-menu="tammMenu"
            />
          </keep-alive>
        </div>
      </div>
      <div
        v-if="!isS"
        class="Appbar-tabs"
      >
        <nav-tabs
          v-if="showTabs"
        />
      </div>

      <div
        v-else
        class="Appbar-moduleCard"
        @click="displayTabs"
      >
        <div class="Appbar-moduleCardContent">
          <div class="Appbar-moduleCardTitle">
            {{ tr("module-" + module.mod_name + "-court") }}
          </div>
          <div class="Appbar-moduleCardTab">
            {{ tabName }}
          </div>
        </div>

        <ox-icon
          class="Appbar-moduleCardIcon"
          icon="chevronDown"
        />
      </div>
    </div>
    <div
      v-if="isXXL"
      id="nav-date"
      class="Appbar-date"
    >
      {{ dateNow }}
    </div>
    <div
      v-if="isXL || uniquePlaceholder"
      class="Appbar-placeholders"
    >
      <placeholder-shortcut
        v-for="(placeholder, index) in placeholders"
        :key="'placeholder-' + index"
        :placeholder="placeholder"
      />
    </div>
    <div
      v-else
      class="Appbar-placeholders"
    >
      <placeholder-shortcut
        :badged="isPlaceholderCounter"
        :placeholder="placeholderCompact"
        @click="openPlaceholders"
      />

      <v-card
        v-if="showPlaceholders"
        class="Appbar-placeholdersList"
        v-click-outside="closePlaceholders"
      >
        <placeholder-line
          v-for="(placeholder, index) of placeholders"
          :key="'placeholder-' + index"
          :placeholder="placeholder"
        />
      </v-card>
    </div>
    <div class="Appbar-context">
      <div
        v-if="isL"
        class="Appbar-group includeGroup"
        :class="groupClass"
        @click="displayGroups"
      >
        <div>
          <div class="Appbar-groupName">{{ group.text }}</div>
          <div class="Appbar-functionName">{{ currentFunction.text }}</div>
        </div>
        <ox-icon
          class="Appbar-dropIcon"
          icon="caretDown"
        />
        <keep-alive>
            <group-selector
              v-if="showGroups"
              class="Appbar-groupSelector"
              :functions="functions"
              :group-selected="group"
              v-model="showGroups"
            />
        </keep-alive>
      </div>

      <div
        v-if="isL"
        class="Appbar-account"
        :class="accountClass"
        @click="displayAccount"
      >
        <div>
          <div class="Appbar-userLastName">{{ userInfo._user_last_name }}</div>
          <div class="Appbar-userFirstName">{{ userInfo._user_first_name }}</div>
          <div class="Appbar-userInitials">{{ userInfo._initial }}</div>
        </div>
        <ox-icon
          class="Appbar-dropIcon"
          icon="caretDown"
        />
      </div>

      <div
        v-else
        @click="displayAccount"
      >
        <div
          class="Appbar-avatar"
          :style="{backgroundColor: '#' + userInfo._color, color: '#' + userInfo._font_color}"
        >
          {{ userInfo._initial }}
        </div>
      </div>

      <div
        v-if="showAccount"
        class="Appbar-userAccount"
      >
        <user-account
          :functions="functions"
          :group-selected="group"
          :info-maj="infoMaj"
          :is-tamm="isTamm"
          :show-group="!isL"
          :user-info="userInfo"
          v-model="showAccount"
        />
      </div>
    </div>

    <div
      v-if="showModuleDetail"
      class="Appbar-moduleDetail"
      v-click-outside="closeTabs"
    >
      <module-detail-mobile
        :module="module"
      />
    </div>
    <ox-notify />
  </div>
</template>

<script lang="ts" src="./Appbar.ts"></script>
<style src="./Appbar.scss" lang="scss"></style>
