/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

import { OxTest, OxThemeCore } from "oxify"
import { Component } from "vue"
import { createLocalVue, shallowMount, Wrapper } from "@vue/test-utils"

import OxAutocomplete from "@/core/components/OxAutocomplete/OxAutocomplete.vue"
import Vuetify from "vuetify"
import OxObject from "@/core/models/OxObject"

/* eslint-disable dot-notation */

/**
 * Test pour OxAutocomplete
 */
export default class OxAutocompleteTest extends OxTest {
    protected component = OxAutocomplete

    /**
     * @inheritDoc
     */
    protected mountComponent (
        props: object = {},
        stubs: { [key: string]: Component | string | boolean } | string[] = {},
        slots: { [key: string]: (Component | string)[] | Component | string } = {}
    ) {
        return shallowMount(
            this.component,
            {
                propsData: props,
                slots: slots,
                vuetify: new Vuetify()
            }
        )
    }

    public testIsAutocompleteComponentByDefault () {
        const object = new OxObject()
        const autocomplete = this.mountComponent({ value: object, url: "test" })
        expect(autocomplete.vm["autocompleteComponent"]).toEqual("v-autocomplete")
    }

    public testIsComboboxComponentWhenSearchable () {
        const object = new OxObject()
        const autocomplete = this.mountComponent({ value: object, url: "test", searchable: true })
        expect(autocomplete.vm["autocompleteComponent"]).toEqual("v-combobox")
    }

    public testHasItem () {
        const autocomplete = this.mountComponent(
            { value: new OxObject(), url: "test" },
            {},
            { item: "<ox-object-autocomplete />" }
        )
        expect(autocomplete.vm["hasItem"]).toBeTruthy()
    }

    public testHasNotItemByDefault () {
        const autocomplete = this.mountComponent(
            { value: new OxObject(), url: "test" }
        )
        expect(autocomplete.vm["hasItem"]).toBeFalsy()
    }

    public testHasSelection () {
        const autocomplete = this.mountComponent(
            { value: new OxObject(), url: "test" },
            {},
            { selection: "<ox-object-selection />" }
        )
        expect(autocomplete.vm["hasSelection"]).toBeTruthy()
    }

    public testHasNotSelectionByDefault () {
        const autocomplete = this.mountComponent(
            { value: new OxObject(), url: "test" }
        )
        expect(autocomplete.vm["hasSelection"]).toBeFalsy()
    }

    public testAutocompleteNotClearableByDefault () {
        const autocomplete = this.mountComponent(
            { value: new OxObject(), url: "test" }
        )
        expect(autocomplete.vm["isClearable"]).toBeFalsy()
    }

    public testAutocompleteNotClearableIfNoValue () {
        const autocomplete = this.mountComponent(
            { value: new OxObject(), url: "test", clearable: true }
        )
        expect(autocomplete.vm["isClearable"]).toBeFalsy()
    }

    public async testClearableAutocomplete () {
        const autocomplete = this.mountComponent(
            { value: new OxObject(), url: "test", clearable: true }
        )
        autocomplete.vm["input"]("test")
        await autocomplete.vm.$nextTick()
        expect(autocomplete.vm["isClearable"]).toBeTruthy()
    }

    public testShowMenuByDefault () {
        const autocomplete = this.mountComponent(
            { value: new OxObject(), url: "test" }
        )
        expect(autocomplete.vm["autocompleteClasses"]).toBe("")
    }

    public testHideMenuOnEnterKey () {
        const autocomplete = this.mountComponent(
            { value: new OxObject(), url: "test" }
        )
        autocomplete.vm["enterDown"]()
        expect(autocomplete.vm["autocompleteClasses"]).toBe("hideMenu")
    }

    public async testShowMenuOnSearch () {
        const autocomplete = this.mountComponent(
            { value: new OxObject(), url: "test" }
        )
        autocomplete.vm["enterDown"]()
        autocomplete.vm["search"] = "Test"
        await autocomplete.vm.$nextTick()
        expect(autocomplete.vm["autocompleteClasses"]).toBe("")
    }

    public async testDebounceLoadItems () {
        const autocomplete = this.mountComponent(
            { value: new OxObject(), url: "test" }
        )
        // @ts-ignore
        const spy = jest.spyOn(autocomplete.vm, "loadItems")
        autocomplete.vm["searchItems"] = jest.fn()
        autocomplete.vm["search"] = "T"
        autocomplete.vm["search"] = "Tes"
        autocomplete.vm["search"] = "Test"
        await new Promise(resolve => setTimeout(resolve, 500))
        autocomplete.vm["search"] = "Test2"
        await autocomplete.vm.$nextTick()
        expect(spy).toBeCalledTimes(2)
    }

    public async testAutocompleteEmitInputOnChange () {
        const autocomplete = this.mountComponent(
            { value: new OxObject(), url: "test" }
        )
        const objectSelected = new OxObject()
        objectSelected.id = "1"
        autocomplete.vm["input"](objectSelected)
        await autocomplete.vm.$nextTick()
        expect(autocomplete.emitted().input).toBeTruthy()
        expect(autocomplete.emitted().input).toEqual([[objectSelected]])
    }

    public async testSearchableEmitInputWhenObjectSelected () {
        const autocomplete = this.mountComponent(
            { value: new OxObject(), url: "test", searchable: true }
        )
        const objectSelected = new OxObject()
        objectSelected.id = "1"
        autocomplete.vm["input"](objectSelected)
        await autocomplete.vm.$nextTick()
        expect(autocomplete.emitted().input).toBeTruthy()
        expect(autocomplete.emitted().input).toEqual([[objectSelected]])
    }

    public async testSearchableDoesNotEmitOnSearch () {
        const autocomplete = this.mountComponent(
            { value: new OxObject(), url: "test", searchable: true }
        )
        autocomplete.vm["input"]("search label")
        await autocomplete.vm.$nextTick()
        expect(autocomplete.emitted().input).toBeFalsy()
    }

    public async testSearchableEmitSearchOnEnter () {
        const autocomplete = this.mountComponent(
            { value: new OxObject(), url: "test", searchable: true }
        )
        autocomplete.vm["search"] = "Search label"
        autocomplete.vm["enterDown"]()
        await autocomplete.vm.$nextTick()
        expect(autocomplete.emitted().enter).toBeTruthy()
        expect(autocomplete.emitted().enter).toEqual([["Search label"]])
    }

    public async testKeepSelectedItemOnBlur () {
        const objectSelected = new OxObject()
        objectSelected.id = "1"
        const autocomplete = this.mountComponent(
            { url: "test", itemText: "id", value: objectSelected }
        )
        autocomplete.vm["onBlur"]()
        await autocomplete.vm.$nextTick()
        expect(autocomplete.vm["itemsData"]).toEqual([objectSelected])
    }

    public async testItemsWatcher () {
        const objectSelected1 = new OxObject()
        objectSelected1.id = "1"
        const objectSelected2 = new OxObject()
        objectSelected2.id = "2"
        const autocomplete = this.mountComponent(
            { value: new OxObject(), url: "test", items: [objectSelected1, objectSelected2] }
        )
        await autocomplete.vm.$nextTick()
        expect(autocomplete.vm["itemsData"]).toEqual([objectSelected1, objectSelected2])
    }

    public testAutocompleteWithDefaultValue () {
        const objectSelected = new OxObject()
        objectSelected.id = "1"
        const autocomplete = this.mountComponent({ url: "test", value: objectSelected })
        expect(autocomplete.vm["itemsData"]).toEqual([objectSelected])
    }

    public testAutocompleteWithDefaultValues () {
        const objectSelected1 = new OxObject()
        objectSelected1.id = "1"
        const objectSelected2 = new OxObject()
        objectSelected2.id = "2"
        const autocomplete = this.mountComponent({ url: "test", value: [objectSelected1, objectSelected2], oxObject: OxObject })
        expect(autocomplete.vm["itemsData"]).toEqual([objectSelected1, objectSelected2])
    }

    public testAutocompleteWithSpecifiedOxObject () {
        const autocomplete = this.mountComponent(
            { url: "test", oxObject: OxObject }
        )
        expect(autocomplete.vm["objectClass"]).toEqual(OxObject)
    }

    public testAutoTyoeInferenceFromDefaultValue () {
        const autocomplete = this.mountComponent(
            { url: "test", value: new OxObject() }
        )
        expect(autocomplete.vm["objectClass"]).toEqual(OxObject)
    }

    public testImpossibleAutoTypeInference () {
        // Ignore console.error from created
        // @ts-ignore
        window.console.error = jest.fn()
        expect(() => this.mountComponent(
            { url: "test" }
        )).toThrowError(new Error("Impossible type inference"))
    }
}

(new OxAutocompleteTest()).launchTests()
