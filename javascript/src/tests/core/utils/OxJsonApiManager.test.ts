/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

import { OxTest } from "oxify"
import { getCollectionFromJsonApi, getObjectFromJsonApi } from "@/core/utils/OxApiManager"
import OxObject from "@/core/models/OxObject"
import { OxJsonApi } from "@/core/types/OxApiTypes"
import { setActivePinia } from "pinia"
import pinia from "@/core/plugins/OxPiniaCore"
import { getObject, getSchema } from "@/core/utils/OxStorage"
import OxCollection from "@/core/models/OxCollection"

/**
 * OxJsonApiManager tests
 */
export default class OxJsonApiManagerTest extends OxTest {
    protected component = "OxJsonApiManager"

    protected beforeAllTests () {
        super.beforeAllTests()
        setActivePinia(pinia)
    }

    private objectJsonApi = {
        data: {
            type: "test_object",
            id: "1",
            attributes: {
                name: "Test object",
                description: "A testing object",
                value: "12"
            },
            relationships: {
                category: {
                    data: {
                        type: "another_object",
                        id: "3000"
                    }
                }
            },
            links: {
                self: "self"
            },
            meta: {
                someKey: "value"
            }
        },
        included: [
            {
                type: "another_object",
                id: "3000",
                attributes: {
                    name: "Object 1",
                    color: "#EA34DA",
                    active: true
                }
            },
            {
                type: "another_object",
                id: "3001",
                attributes: {
                    name: "Object 2",
                    color: "#0EBB84",
                    active: false
                }
            }
        ],
        meta: {
            copyright: "OpenXtrem-2022",
            authors: "dev@openxtrem.com",
            schema: [
                {
                    id: "429b23f96004afaa4ee3b31e7ca34c0e",
                    owner: "test_object",
                    field: "name",
                    type: "str",
                    fieldset: "default",
                    autocomplete: null,
                    placeholder: null,
                    notNull: true,
                    confidential: null,
                    default: null,
                    libelle: "Titre",
                    label: "Titre",
                    description: "Titre"
                },
                {
                    id: "0a9092e87e8ddb890799273a6c8e8596",
                    owner: "test_object",
                    field: "description",
                    type: "str",
                    fieldset: "default",
                    autocomplete: null,
                    placeholder: null,
                    notNull: true,
                    confidential: null,
                    default: null,
                    libelle: "Description",
                    label: "Description",
                    description: "Description"
                }
            ]
        }
    }

    private collectionJsonApi = {
        data: [
            {
                type: "test_object",
                id: "1",
                attributes: {
                    name: "Test object",
                    description: "A testing object",
                    value: "12"
                },
                links: {
                    self: "self"
                }
            },
            {
                type: "test_object",
                id: "2",
                attributes: {
                    name: "Test object",
                    description: "Another testing object",
                    value: "16"
                },
                links: {
                    self: "self"
                }
            },
            {
                type: "test_object",
                id: "3",
                attributes: {
                    name: "Test object",
                    description: "Again Another testing object",
                    value: "16"
                },
                links: {
                    self: "self"
                }
            }
        ],
        links: {
            self: "self",
            schema: "schema",
            history: "history"
        },
        meta: {
            copyright: "OpenXtrem-2022",
            authors: "dev@openxtrem.com"
        }
    }

    public testGetObjectFromJsonApiObject () {
        const result = getObjectFromJsonApi(TestObject, this.objectJsonApi as unknown as OxJsonApi)
        expect(result).toBeInstanceOf(TestObject)
        expect(getObject("another_object", "3000")).toBeInstanceOf(AnotherObject)
        expect(getObject("another_object", "3001")).toBeInstanceOf(AnotherObject)
        expect(getSchema("test_object", "name")).toMatchObject(this.objectJsonApi.meta.schema[0])
        expect(getSchema("test_object", "description")).toMatchObject(this.objectJsonApi.meta.schema[1])
    }

    public testGetObjectFromJsonApiCollection () {
        expect(() => getObjectFromJsonApi(TestObject, this.collectionJsonApi as unknown as OxJsonApi))
            .toThrowError(new Error("Get array instead of object"))
    }

    public testGetCollectionFromJsonApiObject () {
        const result = getCollectionFromJsonApi(TestObject, this.objectJsonApi as unknown as OxJsonApi)
        expect(result).toBeInstanceOf(OxCollection)
        expect(result.objects).toBeInstanceOf(Array)
        expect(result.objects).toHaveLength(1)
        expect(result.objects[0]).toBeInstanceOf(TestObject)
    }

    public testGetCollectionFromJsonApiCollection () {
        const result = getCollectionFromJsonApi(TestObject, this.collectionJsonApi as unknown as OxJsonApi)
        expect(result).toBeInstanceOf(OxCollection)
        expect(result.objects).toBeInstanceOf(Array)
        expect(result.objects).toHaveLength(3)
        expect(result.links).toMatchObject(this.collectionJsonApi.links)
        expect(result.meta).toMatchObject(this.collectionJsonApi.meta)
    }
}

class TestObject extends OxObject {
    constructor () {
        super()
        this.type = "test_object"
    }

    protected _relationsTypes = {
        another_object: AnotherObject
    }
}

class AnotherObject extends OxObject {
    constructor () {
        super()
        this.type = "another_object"
    }
}

(new OxJsonApiManagerTest()).launchTests()
