<script lang="ts">
import { OxTextarea, OxTextField, OxCheckbox, OxSelect, OxDatepicker, OxRadioGroup, OxRadio } from "oxify"
import { defineComponent } from "@vue/composition-api"
import { getSchema } from "@/core/utils/OxStorage"
import { OxSchema } from "@/core/types/OxSchema"

export default defineComponent({
    components: {
        OxTextField,
        OxTextarea,
        OxCheckbox,
        OxSelect,
        OxDatepicker,
        OxRadioGroup,
        OxRadio
    },
    props: {
        ready: {
            default: true,
            type: Boolean
        },
        ressourceName: {
            type: String,
            required: true
        },
        fieldName: {
            type: String,
            required: true
        },
        value: [String, Number, Boolean],
        rules: {
            type: [],
            default: function () {
                return []
            }
        },
        options: {
            default: () => {
                return {}
            },
            type: Object
        },
        customSchema: {
            default: () => {
                return {}
            },
            type: Object
        }
    },
    data: () => {
        return {
            schema: {} as OxSchema
        }
    },
    computed: {
        fieldComponent (): string {
            const type = this.customSchema?.type ?? this.schema?.type

            if (type === "str") {
                return "ox-text-field"
            }
            else if (type === "text") {
                return "ox-textarea"
            }
            else if (type === "duration") {
                return "ox-text-field"
            }
            else if (type === "date" || type === "birthDate" || type === "time") {
                return "ox-datepicker"
            }
            else if (type === "set") {
                return "ox-select"
            }
            else if (type === "bool") {
                return "ox-checkbox"
            }

            return "ox-text-field"
        },
        fieldValue (): string | number | boolean {
            return this.value ?? this.schema.default ?? ""
        },
        fieldWording (): string {
            if (this.customSchema?.libelle) {
                return this.customSchema.libelle
            }

            return this.schema.libelle
        },
        fieldPlaceholder (): string {
            return this.customSchema.placeholder ?? this.schema.placeholder
        },
        fieldRules (): Array<Function> {
            const rules: Array<Function> = this.rules
            if (this.schema.notNull) {
                rules.push(v => (!!v || this.$tr("Missing-field")))
            }
            return rules
        },
        fieldDescription (): string {
            return this.customSchema.description ?? this.schema.description
        },
        formatDate (): string {
            const type = this.customSchema?.type ?? this.schema?.type
            return type === "date" || type === "birthDate" ? "date" : "time"
        },
        list () {
            const list: Array<{_id: string, view: string}> = []
            if (this.schema.values) {
                for (const item of this.schema.values) {
                    list.push({
                        _id: item,
                        view: this.$tr(this.schema.owner + "." + this.schema.field + "." + item)
                    })
                }
            }

            return list
        },
        multipleSelect (): boolean {
            return this.options && this.options.multiple
                ? true
                : this.fieldValue && typeof this.fieldValue !== "boolean"
                    ? (this.fieldValue as string).includes("|")
                    : false
        },
        showRadio (): boolean {
            const type = this.customSchema?.type ?? this.schema?.type
            return type === "enum"
        },
        inputMask (): string | undefined {
            if (this.customSchema?.type === "duration") {
                return "##:##"
            }
            return undefined
        }
    },
    methods: {
        change (value: string|boolean): void {
            this.$emit("input", value)
        }
    },
    watch: {
        ready: {
            handler (newReady) {
                if (newReady) {
                    this.schema = getSchema(this.ressourceName, this.fieldName)
                }
            },
            immediate: true
        }
    }
})
</script>

<template>
  <div class="OxField">
    <component
      v-if="!showRadio"
      :format="formatDate"
      :is="fieldComponent"
      :label="fieldWording"
      :list="list"
      :mask="inputMask"
      :multiple="multipleSelect"
      :placeholder="fieldPlaceholder"
      :rules="fieldRules"
      :show-loading="!ready"
      :value="fieldValue"
      @change="change"
    />

    <ox-radio-group
      v-else
      :column="options.column"
      :label="fieldWording"
      :row="options.row"
      :rules="fieldRules"
      :value="fieldValue"
      @input="change"
    >
      <template #default>
        <ox-radio
          v-for="item of list"
          :key="item._id"
          :disabled="options.disabled"
          :emphasis="options.emphasis"
          :expand="options.expand"
          :label="item.view"
          :value="item._id"
        >
          <template #label>
            <slot name="label" v-bind:label="item.view">
              {{ item.view }}
            </slot>
          </template>
        </ox-radio>
      </template>
    </ox-radio-group>
  </div>
</template>

<style lang="scss" src="./OxField.scss"/>
