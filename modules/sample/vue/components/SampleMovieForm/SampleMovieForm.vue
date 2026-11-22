<!--
  @author  SAS OpenXtrem <dev@openxtrem.com>
  @license https://www.gnu.org/licenses/gpl.html GNU General Public License
  @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
-->

<script lang="ts">
import SampleMovie from "../../models/SampleMovie"
import { OxSections, OxButton, OxChip, OxForm } from "oxify"
import OxField from "@/core/components/OxField/OxField.vue"
import { prepareForm } from "@/core/utils/OxSchemaManager"
import { cloneObject } from "@/core/utils/OxObjectTools"
import { isEqual, cloneDeep } from "lodash"
import { createJsonApiObjects, updateJsonApiObject } from "@/core/utils/OxApiManager"
import OxAutocomplete from "@/core/components/OxAutocomplete/OxAutocomplete.vue"
import SamplePerson from "../../models/SamplePerson"
import SampleCategory from "../../models/SampleCategory"
import SamplePersonAutocomplete from "../SamplePersonAutocomplete/SamplePersonAutocomplete.vue"
import { defineComponent, PropType } from "@vue/composition-api"
import { OxUrlBuilder } from "@/core/utils/OxUrlTools"
import { addInfo } from "@/core/utils/OxNotifyManager"

export default defineComponent({
    name: "SampleMovieForm",
    components: {
        SamplePersonAutocomplete,
        OxAutocomplete,
        OxField,
        OxSections,
        OxButton,
        OxChip
    },
    props: {
        movie: {
            required: false,
            type: Object as PropType<SampleMovie>
        },
        actors: {
            required: false,
            type: Array as PropType<SamplePerson[]>
        },
        personsUrl: {
            type: String,
            required: true
        },
        categoriesUrl: String,
        nationalitiesUrl: String,
        movieUrl: String
    },
    data: () => {
        return {
            formReady: false,
            formValidated: false,
            ressourceName: "sample_movie",
            oldMovie: null,
            movieMutated: {} as SampleMovie,
            newPersons: [] as SamplePerson[],
            optionsCsa: { row: true },
            optionsLanguages: { multiple: true },
            samplePersonClass: SamplePerson,
            sampleCategoryClass: SampleCategory
        }
    },
    computed: {
        directorUrl (): string {
            return new OxUrlBuilder(this.personsUrl).addFilter("is_director", "equal", "1").toString()
        },
        title (): string {
            return this.movie ? this.$tr("CSampleMovie-title-modify") : this.$tr("CSampleMovie-title-create")
        },
        sections () {
            return [{
                title: "Informations principales",
                name: "informations"
            }, {
                title: "Casting",
                name: "casting"
            }, {
                title: "Autres informations",
                name: "others"
            }]
        }
    },
    methods: {
        async save () {
            const validate = (this.$refs.form as OxForm).validate()

            if (!validate) {
                return
            }

            if (!isEqual(this.newPersons, this.actors)) {
                this.movieMutated.addRelationCastings(this.newPersons)
            }

            if (this.movie) {
                await updateJsonApiObject(SampleMovie, this.movie, this.movieMutated)
                this.$emit("close", "update", this.movieMutated)
                addInfo(this.$tr("CSampleMovie-msg-modify"))
            }
            else if (this.movieUrl) {
                await createJsonApiObjects(SampleMovie, this.movieMutated, this.movieUrl)
                this.$emit("close", "create", this.movieMutated)
                addInfo(this.$tr("CSampleMovie-msg-create"))
            }
        },
        removeItem (item) {
            const index = this.newPersons.indexOf(item)
            if (index >= 0) this.newPersons.splice(index, 1)
        },
        closeForm () {
            this.$emit("close")
        },
        updateDuration (duration: string) {
            if (duration.length === 5) {
                // Set to default time format
                this.movieMutated.duration = duration + ":00"
            }
        }
    },
    async created () {
        this.movieMutated = this.movie ? cloneObject(this.movie) : new SampleMovie()
        if (this.actors) {
            this.newPersons = cloneDeep(this.actors)
        }
        this.formReady = await prepareForm(this.ressourceName, ["default", "details"])
    }
})
</script>

<template>
  <v-form
    v-model="formValidated"
    class="SampleMovieForm"
    ref="form"
    @submit.prevent="save"
  >
    <div class="SampleMovieForm-header">
      <h5 class="SampleMovieForm-title">{{ title }}</h5>
      <div class="SampleMovieForm-headerActions">
        <ox-button
          button-style="tertiary-dark"
          :label="$tr('Cancel')"
          @click="closeForm"
        />
        <ox-button
          button-style="primary"
          :label="$tr('Save')"
          type="submit"
        />
      </div>
    </div>
    <ox-sections :sections="sections">
      <template #informations>
        <div class="SampleMovieForm-content">
          <ox-field
            v-model="movieMutated.title"
            field-name="name"
            :ready="formReady"
            :ressource-name="ressourceName"
          />

          <ox-field
            v-model="movieMutated.description"
            field-name="description"
            :ready="formReady"
            :ressource-name="ressourceName"
          />

          <div class="SampleMovieForm-date">
            <ox-field
              v-model="movieMutated.releaseData"
              field-name="release"
              :ready="formReady"
              :ressource-name="ressourceName"
            />
            <ox-field
              :custom-schema="{ type: 'duration', placeholder: '01:45' }"
              field-name="duration"
              :ready="formReady"
              :ressource-name="ressourceName"
              :rules="[
                v => /[0-9]{2}:[0-5][0-9]/.test(v) || $tr('CSampleMovie-duration-invalidField')
              ]"
              :value="movieMutated.duration"
              @input="updateDuration"
            />
          </div>

          <ox-autocomplete
            v-model="movieMutated.category"
            item-text="name"
            :label="$tr('CSampleMovie-category_id')"
            :min-char-search="1"
            :ox-object="sampleCategoryClass"
            :placeholder="$tr('CSampleCategory-chooseLabel')"
            search-field="name"
            :url="categoriesUrl"
          />
        </div>
      </template>

      <template #casting>
        <div class="SampleMovieForm-content">
          <ox-autocomplete
            v-model="movieMutated.director"
            item-text="fullName"
            :label="$tr('CSamplePerson-is_director')"
            :placeholder="$tr('CSamplePerson-choose-director')"
            :ox-object="samplePersonClass"
            :url="directorUrl"
          >
            <template #item="{ item: person }">
              <sample-person-autocomplete :person="person" />
            </template>
          </ox-autocomplete>

          <ox-autocomplete
            v-model="newPersons"
            item-text="fullName"
            :label="$tr('CSampleCasting-actors')"
            multiple
            :ox-object="samplePersonClass"
            :placeholder="$tr('CSamplePerson-choose-actors')"
            :url="personsUrl"
          >
            <template #selection="slotProps">
              <OxChip
                close
                @click:close="removeItem(slotProps.item)"
              >
                <v-avatar
                  class="SampleMovieForm-avatarChips"
                >
                  <img
                  :src="slotProps.item.profilePicture"
                  class="SampleMovieForm-image"
                  alt=""
                  />
                </v-avatar>
                {{ slotProps.item.fullName }}
              </OxChip>
            </template>
            <template #item="{ item: person }">
              <sample-person-autocomplete :person="person" />
            </template>
          </ox-autocomplete>
        </div>
      </template>

      <template #others>
        <div class="SampleMovieForm-content">
          <ox-field
            v-model="movieMutated.csa"
            field-name="csa"
            :options="optionsCsa"
            :ready="formReady"
            :ressource-name="ressourceName"
          >
            <template #label="slotProps">
              <ox-chip
                :error="slotProps.label === '-18'"
                :small="true"
              >
                {{slotProps.label}}
              </ox-chip>
            </template>
          </ox-field>

          <ox-field
            v-model="movieMutated.languagesData"
            field-name="languages"
            :options="optionsLanguages"
            :ready="formReady"
            :ressource-name="ressourceName"
          />
        </div>
      </template>
    </ox-sections>
  </v-form>
</template>

<style lang="scss" src="./SampleMovieForm.scss" />
