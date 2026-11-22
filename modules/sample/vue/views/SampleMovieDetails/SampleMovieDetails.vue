<!--
  @package Openxtrem\Sample
  @author  SAS OpenXtrem <dev@openxtrem.com>
  @license https://www.gnu.org/licenses/gpl.html GNU General Public License
  @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
-->

<script lang="ts">
import {
    OxAlert,
    OxBeautify,
    OxButton,
    OxDropdownButton,
    OxDropdownButtonActionModel,
    OxIcon,
    OxThemeCore
} from "oxify"
import {
    deleteJsonApiObject,
    getObjectFromJsonApiRequest
} from "@/core/utils/OxApiManager"
import SampleMovie from "../../models/SampleMovie"
import SamplePerson from "../../models/SamplePerson"
import { defineComponent } from "@vue/composition-api"
import { OxUrlBuilder } from "@/core/utils/OxUrlTools"
import { addInfo } from "@/core/utils/OxNotifyManager"
const SampleMovieForm = () => import("@modules/sample/vue/components/SampleMovieForm/SampleMovieForm.vue")
const SampleDirectorLine = () => import("@modules/sample/vue/components/SampleDirectorLine/SampleDirectorLine.vue")
const SampleActorLine = () => import("@modules/sample/vue/components/SampleActorLine/SampleActorLine.vue")

// TODO: Alpha

export default defineComponent({
    name: "SampleMovieDetails",
    components: {
        OxAlert,
        OxButton,
        OxIcon,
        OxBeautify,
        OxDropdownButton,
        SampleDirectorLine,
        SampleActorLine,
        SampleMovieForm
    },
    props: {
        movieUrl: {
            type: String,
            required: true
        },
        castingUrl: {
            type: String,
            required: true
        },
        categoriesUrl: String,
        nationalitiesUrl: String,
        personsUrl: String,
        backLink: String
    },
    data () {
        return {
            dropdownActions: [
                {
                    label: this.$tr("Edit"),
                    eventName: "updateItem",
                    icon: "edit"
                },
                {
                    label: this.$tr("Delete"),
                    eventName: "deleteItem",
                    icon: "delete"
                }
            ] as OxDropdownButtonActionModel[],
            loading: true,
            movie: null as unknown as SampleMovie,
            showModal: false,
            showUpdateForm: false,
            url: null as unknown as URL
        }
    },
    computed: {
        director (): SamplePerson|null {
            return this.movie ? this.movie.director : null
        },
        actors (): (SamplePerson | null)[] {
            return this.movie ? this.movie.actors : []
        },
        colorYellow () {
            return OxThemeCore.yellowText
        },
        backgroundGrey () {
            return OxThemeCore.grey300
        }
    },
    methods: {
        updateMovie (): void {
            // TODO: Gérer l'history avec un composable
            const url = new OxUrlBuilder(window.location.href).buildUrl()
            url.searchParams.set("edit", "update")
            window.history.pushState({}, "", url)
            this.showUpdateForm = true
        },
        async reset (reload: string): Promise<void> {
            const url = new OxUrlBuilder(window.location.href).buildUrl()
            url.searchParams.delete("edit")
            window.history.pushState({}, "", url)

            if (reload) {
                const urlMovie = new OxUrlBuilder(this.movie.self).addFieldset("default").addFieldset("details")
                    .addRelation("category").addRelation("director").addRelation("actors").withPermissions()
                this.movie = await getObjectFromJsonApiRequest<SampleMovie>(SampleMovie, urlMovie.toString())
            }

            this.showUpdateForm = false
        },
        askDeleteMovie (): void {
            this.showModal = !this.showModal
        },
        async deleteMovie (): Promise<void> {
            await deleteJsonApiObject(this.movie)
            addInfo(this.$tr("CSampleMovie-msg-delete"))

            if (this.backLink) {
                window.location.href = this.backLink
            }
        }
    },
    async created () {
        // Call Movie
        const url = new OxUrlBuilder(this.movieUrl).addRelation("actors")
        this.movie = await getObjectFromJsonApiRequest(SampleMovie, url.toString())

        // TODO: Gestion de l'état avec le composable
        this.showUpdateForm = window.location.href.toString().includes("update")
        this.loading = false
    }
})
</script>

<template>
  <div
    class="SampleMovieDetails"
  >
    <!-- Alert delete movie -->
    <ox-alert
      v-if="!loading"
      :title="$tr('sample_movie-confirm-Delete this object-1') + '?'"
      :label-accept="$tr('Delete')"
      :label-cancel="$tr('Cancel')"
      show-cancel
      v-model="showModal"
      @accept="deleteMovie"
    >
      <template #default>
        <div class="SampleMovieDetails-alert">
          {{ $tr('sample_movie-confirm-Delete this object-1') }}
          <span class="SampleMovieDetails-alertTitle"> "{{movie.title}}" </span>
          {{ $tr('sample_movie-confirm-Delete this object-2') }}
        </div>
      </template>
    </ox-alert>

    <!-- Update Form Movie-->
    <sample-movie-form
      v-if="showUpdateForm"
      :actors="actors"
      :categories-url="categoriesUrl"
      :movie="movie"
      :nationalities-url="nationalitiesUrl"
      :persons-url="personsUrl"
      @close="reset"
    />

    <!-- Content details movie -->
    <div
      v-else
      class="SampleMovieDetails-content"
    >
      <div class="SampleMovieDetails-back">
        <ox-button
          button-style="tertiary"
          :href="backLink"
          icon="back"
          label="Retour au catalogue"
        />
      </div>

      <div class="SampleMovieDetails-container">
        <div class="SampleMovieDetails-cover">
          <img
            v-if="movie && movie.image"
            alt="Cover"
            class="SampleMovieDetails-cover"
            :src="movie.image"
          />
          <div
            v-else
            class="SampleMovieDetails-cover"
          />
        </div>

        <div class="SampleMovieDetails-content">
          <div class="SampleMovieDetails-header">
            <p
              v-if="movie && movie.title"
              class="SampleMovieDetails-title"
            >
              {{ movie.title }}
            </p>
            <p v-else-if="loading" class="SampleMovieDetails-title">
              <v-skeleton-loader
                type="heading"
                :width="450"
              />
            </p>
            <ox-dropdown-button
              :alt-actions="dropdownActions"
              button-style="tertiary-dark"
              :left="true"
              @updateitem="updateMovie"
              @deleteitem="askDeleteMovie"
            />
          </div>
          <div
            v-if="movie && movie.category"
            class="SampleMovieDetails-category"
          >
            {{ movie.category.name }}
          </div>
          <v-skeleton-loader
            v-else-if="loading"
            class="SampleMovieDetails-category"
            type="text"
            :width="100"
          />

          <div class="SampleMovieDetails-plus">
            <div class="SampleMovieDetails-plusContainer">
              <div class="SampleMovieDetails-plusTitle">{{ $tr('CSampleMovie-duration') }}</div>
              <div class="SampleMovieDetails-plusText">
                <ox-beautify
                  v-if="movie && movie.duration"
                  :value="movie.duration"
                />
                <v-skeleton-loader
                  v-else-if="loading"
                  type="text"
                  :width="50"
                />
              </div>
            </div>

            <div class="SampleMovieDetails-plusContainer">
              <div class="SampleMovieDetails-plusTitle">{{ $tr('CSampleMovie-release') }}</div>
              <div class="SampleMovieDetails-plusText">
                <ox-beautify
                  v-if="movie && movie.release"
                  :value="movie.release"
                />
                <v-skeleton-loader
                  v-else-if="loading"
                  type="text"
                  :width="80"
                />
              </div>
            </div>

            <div class="SampleMovieDetails-plusContainer">
              <div class="SampleMovieDetails-plusTitle">{{ $tr('CSampleMovie-language') }}</div>
              <div
                v-if="movie && movie.languages"
                class="SampleMovieDetails-plusText"
              >
                {{ movie.languages }}
              </div>
              <v-skeleton-loader
                v-else-if="loading"
                type="text"
                :width="100"
              />
            </div>

            <div class="SampleMovieDetails-plusContainer">
              <div class="SampleMovieDetails-plusTitle">{{ $tr('CSampleMovie-note') }}</div>
              <div class="SampleMovieDetails-plusText">
                <v-rating
                  :background-color="backgroundGrey"
                  class="SampleMovieDetails-plusRating"
                  :color="colorYellow"
                  half-increments
                  readonly
                  size="20"
                  :value="3.5"
                />

                <div class="SampleMovieDetails-plusRatingDetails">
                  102
                  <ox-icon icon="account" size="20"/>
                </div>
              </div>
            </div>
          </div>

          <div
            v-if="loading || movie && movie.description"
            class="SampleMovieDetails-subtitle"
          >
            {{ $tr('CSampleMovie-description') }}
          </div>
          <div
            v-if="movie && movie.description"
            class="SampleMovieDetails-text"
          >
            {{movie.description}}
          </div>
          <v-skeleton-loader
            v-else-if="loading"
            class="SampleMovieDetails-text"
            type="text@6"
            :width="500"
          />

          <div
            v-if="loading || movie && movie.director"
            class="SampleMovieDetails-subtitle"
          >
            {{ $tr('CSamplePerson-is_director') }}
          </div>
          <div class="SampleMovieDetails-director">
            <sample-director-line :director="director"/>
          </div>

          <div
            v-if="actors && actors.length > 0"
            class="SampleMovieDetails-subtitle"
          >
            {{ $tr('CSampleCasting-actors') }}
          </div>
          <div  class="SampleMovieDetails-actors">
            <sample-actor-line
              v-for="actor of actors"
              :key="actor.id"
              :actor="actor"
            />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style src="./SampleMovieDetails.scss" lang="scss"></style>
