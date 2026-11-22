/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

import OxObject from "@/core/models/OxObject"

export default class SampleNationality extends OxObject {
    constructor () {
        super()
        this.type = "sample_nationality"
    }

    get name (): string {
        if (!this.attributes.name) {
            return ""
        }
        return this.attributes.name.charAt(0).toUpperCase() + this.attributes.name.slice(1)
    }

    get code (): string {
        return this.attributes.code
    }

    get flag (): string {
        return this.attributes.flag
    }
}
