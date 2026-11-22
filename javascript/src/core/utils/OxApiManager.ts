/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

import OxObject from "@/core/models/OxObject"
import OxCollection from "@/core/models/OxCollection"
import { OxJsonApi, OxJsonApiRelationships } from "@/core/types/OxApiTypes"
import {
    createJsonApiSkeleton,
    dataTransformer,
    includedTransformer,
    oxObjectTransformer,
    extractSchemasFromJsonApi
} from "@/core/utils/OxJsonApiTransformer"
import { storeObjects, storeSchemas } from "@/core/utils/OxStorage"
import oxApiService from "@/core/utils/OxApiService"
import { getOxObjectsDiff, getRelationDiff } from "@/core/utils/OxObjectTools"
import { OxCollectionMeta } from "@/core/types/OxCollectionTypes"

/**
 * Return an OxObject from an url
 * @param {OxObject} ObjectType - Type of OxObject that should be returned
 * @param {string} url - Resource url
 *
 * @returns {OxObject}
 */
export async function getObjectFromJsonApiRequest<DataType extends OxObject> (
    ObjectType: new() => DataType,
    url: string
): Promise<DataType> {
    const resource = await oxApiService.get<OxJsonApi>(url)
    return getObjectFromJsonApi(ObjectType, resource.data)
}

/**
 * Return a OxCollection from an url
 * @param {OxObject} ObjectType - Type of OxObject that should be returned
 * @param {string} url - Resource url
 *
 * @returns {OxCollection}
 */
export async function getCollectionFromJsonApiRequest<DataType extends OxObject> (
    ObjectType: new() => DataType,
    url: string
): Promise<OxCollection<DataType>> {
    const resource = await oxApiService.get<OxJsonApi>(url)
    return getCollectionFromJsonApi(ObjectType, resource.data)
}

/**
 * Return an OxObject from a JSON:API
 * @param {OxObject} ObjectType - Type of OxObject that should be returned
 * @param {OxJsonApi} json - The JSON:API containing the object
 *
 * @returns {OxObject}
 */
export function getObjectFromJsonApi<DataType extends OxObject> (
    ObjectType: new() => DataType,
    json: OxJsonApi
): DataType {
    const result = dataTransformer(ObjectType, json)

    if (Array.isArray(result)) {
        throw new Error("Get array instead of object")
    }

    storeIncluded(ObjectType, json)
    storeSchemas(extractSchemasFromJsonApi(json))

    return result
}

/**
 * Return a OxCollection from a JSON:API
 * @param {OxObject} ObjectType - Type of OxObject that should be returned
 * @param {OxJsonApi} json - The JSON:API containing the objects
 *
 * @returns {OxCollection}
 */
export function getCollectionFromJsonApi<DataType extends OxObject> (
    ObjectType: new() => DataType,
    json: OxJsonApi
): OxCollection<DataType> {
    const collection = new OxCollection<DataType>()
    let result = dataTransformer(ObjectType, json)

    if (!Array.isArray(result)) {
        result = [result]
    }

    collection.objects = result
    collection.links = json.links
    collection.meta = json.meta as OxCollectionMeta

    storeIncluded(ObjectType, json)
    storeSchemas(extractSchemasFromJsonApi(json))

    return collection
}

/**
 * Post to backend given objects in JSON:API format
 * @param ObjectType - OxObject class to create
 * @param objects - Object or objects to store
 * @param {string } url - Url to call
 */
// @TODO: Supprimer le premier paramètre qui peut être calculé
export async function createJsonApiObjects<O extends OxObject> (
    ObjectType: new() => O,
    objects: O,
    url: string
): Promise<O>;
export async function createJsonApiObjects<O extends OxObject> (
    ObjectType: new() => O,
    objects: O[],
    url: string
): Promise<OxCollection<O>>;
export async function createJsonApiObjects<O extends OxObject> (
    ObjectType: new() => O,
    objects: O | O[],
    url: string
): Promise<O | OxCollection<O>> {
    const isArray = Array.isArray(objects)
    let data
    if (isArray) {
        data = objects.map((object: O) => {
            return oxObjectTransformer(object)
        })
    }
    else {
        data = oxObjectTransformer(objects)
    }
    const response = (await oxApiService.post<OxJsonApi>(url, createJsonApiSkeleton(data))).data

    return getCollectionFromJsonApi(ObjectType, response)
}

/**
 * Update backend object based on modified fields between original object and mutated object
 * @param ObjectType - OxObject class to update
 * @param {OxObject} from - Original object
 * @param {OxObject} to - Mutated object
 *
 * @returns {OxObject} The updated object
 */
// @TODO: Supprimer le premier paramètre qui peut être calculé + si from et to n'ont pas le même prototype => throw error
export async function updateJsonApiObject<O extends OxObject> (ObjectType: new() => O, from: O, to: O): Promise<O> {
    const diffAttr = getOxObjectsDiff(from, to)
    const diffRelations = getRelationDiff(from, to)
    const object = oxObjectTransformer(to)
    object.attributes = diffAttr
    object.relationships = diffRelations as OxJsonApiRelationships

    if (to.links.self === undefined) {
        throw new Error("Missing self links on object")
    }

    const response = (await oxApiService.patch<OxJsonApi>(to.links.self, createJsonApiSkeleton(object))).data
    return getObjectFromJsonApi(ObjectType, response)
}

/**
 * delete backend object
 * @param {OxObject} object - Original object
 *
 * @returns {boolean} Success request
 */
export async function deleteJsonApiObject<O extends OxObject> (object: O): Promise<boolean> {
    if (object.links.self === undefined) {
        throw new Error("Missing self links on object")
    }

    await oxApiService.delete<OxJsonApi>(object.links.self)

    return true
}

/**
 * Store included og given JSON:API
 *
 * @param {OxObject} ObjectType - Type of OxObject that should be store
 * @param {OxJsonApi} json - The JSON:API containing the included
 */
function storeIncluded<T extends OxObject> (ObjectType: new () => T, json: OxJsonApi) {
    const included = includedTransformer(ObjectType, json)
    if (included.length) {
        storeObjects(included)
    }
}
