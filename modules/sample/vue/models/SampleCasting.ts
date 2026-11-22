/**
 * @package Openxtrem\Sample
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */
import OxObject from "@/core/models/OxObject"
import SamplePerson from "./SamplePerson"

export default class SampleCasting extends OxObject {
    constructor () {
        super()
        this.type = "sample_casting"
    }

    protected _relationsTypes = {
        sample_person: SamplePerson
    }

    get mainActor (): boolean {
        return this.attributes.is_main_actor
    }

    get actor (): SamplePerson {
        return this.loadForwardRelation<SamplePerson>("actor") as SamplePerson
    }

    get actorId (): string {
        return this.attributes.actor_id
    }

    set actorId (id: string) {
        this.set("actor_id", id)
    }
}
