<?php

/**
 * @package Hospilibre\Cli\Tests
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Tests\Unit\Seed;

use Hospilibre\Mediusers\CFunctions;
use Hospilibre\Admin\CUser;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\System\CFixturesReference;
use Hospilibre\System\CUserAction;
use Ox\Cli\Seed\SeedException;
use Ox\Cli\Seed\SeedPurger;
use Ox\Core\CMbDT;
use Ox\Tests\UnitTestHospilibre;

/**
 * UN COMPTE SEMÉ QUI S'EST CONNECTÉ DOIT-IL RESTER PURGEABLE ?
 *
 * ⚠ CE TEST EXISTE POUR ÉPROUVER UNE PRÉMISSE, PAS POUR PROTÉGER UN CORRECTIF. La carte #179
 * affirme qu'« une seule ligne `CUserAction` suffit à rendre un semis impurgeable » : constaté le
 * 2026-10-05 après une connexion de diagnostic, `canDelete()` a refusé de supprimer le compte —
 * « CMbObject-msg-nodelete-backrefs : 1 CUser-back-owned_actions » —, donc sa fonction, donc
 * l'établissement.
 *
 * MAIS CE MESSAGE N'EST JAMAIS ATTEINT SUR LE CHEMIN DE LA PURGE, et la nuance est d'infra, qui
 * l'a vérifiée dans l'image en service plutôt que de me croire. J'avais écrit que « `purge()`
 * court-circuite le contrôle » ; c'est plus exact et plus fort que cela — LE CONTRÔLE N'EST PAS
 * ATTEINT DU TOUT. Les trois maillons, dans l'ordre :
 *
 *   — `CStoredObject::purge()` ligne 4317 pose `$this->_purge = "1"` juste avant le `delete()` de
 *     la ligne 4319 ;
 *   — ★ `CStoredObject::delete()` ligne 3858 : `if (!$this->_purge) { … canDeleteEx() … }`. L'appel
 *     à `canDeleteEx()` — et donc le message « CMbObject-msg-nodelete-backrefs » de la ligne 4037 —
 *     est SOUS CONDITION. C'est le maillon que je n'avais pas cité, et c'est lui qui conclut ;
 *   — la ligne 4302 purge d'abord les références arrière dont le champ est `_notNull`, ce qu'est
 *     `CUserAction::user_id` (`modules/system/classes/CUserAction.php:186`).
 *
 * Et `SeedPurger` appelle bien `purge()`, pas `delete()`.
 *
 * ⇒ Il y a donc deux lectures possibles du constat du 5 octobre, et elles ne demandent pas le même
 *   travail :
 *     — soit la purge EST bloquée, et il faut la corriger ;
 *     — soit c'est un `delete()` MANUEL de diagnostic qui a été refusé, la purge n'ayant jamais été
 *       essayée sur ce compte, et la carte décrit alors une gêne d'exploitation et non un défaut
 *       du semis.
 *
 * Supposer l'une ou l'autre coûterait soit un correctif inutile, soit un resemis de production
 * lancé sur une purge qu'on sait condamnée. Ce test tranche, et il tranche par le chemin RÉEL :
 * celui que `SeedPurger` emprunte.
 *
 * ★ RÉSULTAT, LE 2026-10-07 : C'EST LA SECONDE LECTURE QUI EST VRAIE. Un compte portant une action
 * SE PURGE, l'action partant avec lui comme référence arrière `notNull`. Et `canDeleteEx()` refuse
 * bien, ce qui confirme le constat du 5 octobre tout en le situant : il portait sur le chemin
 * `delete()`, que le resemis n'emprunte pas. La carte #179 décrit donc une gêne pour qui supprime
 * un compte à la main, PAS un défaut qui rendrait un semis impurgeable.
 *
 * ⚠ CE QUE CE TEST N'ÉTABLIT PAS, et il faut le dire parce que j'ai déjà surévalué une conclusion
 * aujourd'hui : il retire UN obstacle supposé, il ne prouve pas qu'une purge de production
 * réussira. L'échec réel du 5 octobre venait d'une TABLE SUBSTITUÉE, cause distincte et toujours
 * présente. Et l'action écrite ici n'a pas d'enregistrements `user_action_data` attachés, là où une
 * connexion réelle peut en produire ; `purge()` étant récursif ils devraient suivre, mais ce test
 * ne le montre pas.
 */
class SeedPurgerUserActionTest extends UnitTestHospilibre
{
    /** Classe de traçage propre à ce test. */
    private const MARKER = 'test-seed-purger-user-action';

    /** @var CUser[] */
    private $users = [];

    /** @var CMediusers[] */
    private $mediusers = [];

    protected function tearDown(): void
    {
        // Nettoyage par les deux bouts, et TOLÉRANT À L'ÉCHEC : si la purge ne passe pas — ce qui
        // est précisément l'hypothèse éprouvée —, le compte reste et doit être retiré de force,
        // sinon le test suivant hériterait d'un compte orphelin.
        $reference = new CFixturesReference();

        foreach ($reference->loadList(['fixtures_class' => "= '" . self::MARKER . "'"]) as $_reference) {
            $_reference->purge();
        }

        foreach ($this->users as $user) {
            if (!$user->_id) {
                continue;
            }

            foreach ((new CUserAction())->loadList(['user_id' => "= '{$user->_id}'"]) as $_action) {
                $_action->purge();
            }

            $user->purge();
        }

        foreach ($this->mediusers as $practitioner) {
            if (!$practitioner->_id) {
                continue;
            }

            foreach ((new CUserAction())->loadList(['user_id' => "= '{$practitioner->_id}'"]) as $_action) {
                $_action->purge();
            }

            // ⚠ LE COMPTE `CUser` D'ABORD, PUIS LE PRATICIEN : c'est le défaut même qu'on éprouve,
            // donc le nettoyage doit l'éviter explicitement au lieu d'en souffrir.
            $user = new CUser();

            if ($user->load($practitioner->_id)) {
                $user->purge();
            }

            $practitioner->purge();
        }

        $this->users     = [];
        $this->mediusers = [];

        parent::tearDown();
    }

    /**
     * ★ LE TEST QUI TRANCHE : une action sur le compte, puis la purge par le chemin du semis.
     *
     * @throws SeedException
     */
    public function testAUserWithAnActionIsStillPurgeable(): void
    {
        $user = $this->traceAUserWithAnAction();

        $purger = new SeedPurger(self::MARKER);

        $this->assertSame(1, $purger->count(), 'La trace doit exister avant la purge.');

        $purged = $purger->purge();

        $this->assertSame(
            [],
            $purger->failures(),
            "LA PURGE NE DOIT PAS ÉCHOUER. Si cette assertion tombe, la prémisse de la carte #179"
            . ' est vérifiée sur le chemin du semis et le correctif est nécessaire. Le message'
            . ' d\'échec dit alors lequel des deux chemins a refusé.'
        );

        $this->assertSame(1, $purged, 'Le compte doit être compté comme supprimé.');
        $this->assertSame(0, $purger->count(), 'La trace doit partir avec sa cible.');

        // ET L'ACTION DOIT ÊTRE PARTIE AVEC LUI, pas être restée orpheline : `user_id` est un
        // « ref notNull », donc une ligne qui survivrait pointerait un compte inexistant.
        $this->assertSame(
            [],
            (new CUserAction())->loadIds(['user_id' => "= '{$user->_id}'"]),
            "L'action doit être purgée comme référence arrière notNull. Une ligne survivante"
            . ' pointerait un compte supprimé.'
        );
    }

    /**
     * ★ LE TÉMOIN : le même compte, sans action, doit se purger aussi.
     *
     * Sans lui, un échec du test précédent pourrait venir de la fabrication du compte et non de
     * l'action — et un succès ne dirait pas que l'action a été rencontrée.
     *
     * @throws SeedException
     */
    public function testAUserWithoutAnyActionIsPurgeableToo(): void
    {
        $this->traceAUserWithAnAction(false);

        $purger = new SeedPurger(self::MARKER);

        $this->assertSame(1, $purger->purge(), 'Un compte sans action doit se purger.');
        $this->assertSame([], $purger->failures());
    }

    /**
     * ⚠ ET CE QUE `delete()` FAIT, LUI, consigné pour que la distinction reste visible.
     *
     * C'est le chemin du 5 octobre : une suppression demandée à la main sur un compte qui s'est
     * connecté. Si cette assertion échoue un jour, c'est que `canDeleteEx()` a cessé de compter les
     * actions comme un obstacle — et la carte #179 n'aurait plus d'objet du tout.
     */
    public function testAPlainDeleteIsRefusedOnAUserWithAnAction(): void
    {
        $user = $this->traceAUserWithAnAction();

        $this->assertNotSame(
            '',
            (string)$user->canDeleteEx(),
            "canDeleteEx() doit refuser : c'est le constat du 2026-10-05, et c'est ce qui le"
            . " distingue de purge(). Le resemis n'emprunte PAS ce chemin."
        );
    }

    /**
     * ★★ LA CLASSE QUE LE SEMIS PURGE EST `CMediusers`, ET LE DÉFAUT NE S'Y REPRODUIT PAS NON PLUS.
     *
     * ⚠ HISTORIQUE, PARCE QU'IL EST LE CONTENU UTILE DE CE TEST ET QU'IL EST FAIT DE MES ERREURS.
     *
     * 1. Le 2026-10-05, une purge sur CE BANC a rapporté « 3 objet(s) non supprimé(s) » avec
     *    « CMediusers-3348 : CMbObject-msg-nodelete-backrefs: 1 CUser-back-owned_actions » et le
     *    même message sur « CFunctions-223 ». La carte #179 est née de là.
     * 2. J'ai attribué cette sortie à un rapport d'infra sur la PRODUCTION. C'était faux deux fois :
     *    elle vient de MA propre exécution, et de `.39`. Retrouvée dans la transcription de session
     *    sous forme de `tool_result`, et dans `scratchpad/seed-bilan.log` : profil « small », 11 238
     *    objets tracés. Infra a cherché ce rapport dans ses fichiers et ne l'a pas trouvé — pour la
     *    bonne raison qu'il n'était pas d'eux.
     * 3. J'ai ensuite affirmé que la cause réelle était « une table substituée ». Faux aussi : le
     *    message nomme `owned_actions`. La table substituée était un autre incident, du 4 octobre.
     * 4. J'ai alors « réfuté » #179 avec un test sur `CUser` — une classe dont le semis ne trace
     *    AUCUN exemplaire. Mesuré : 121 `CMediusers`, 8 `CFunctions`, zéro `CUser`.
     * 5. Ce test-ci éprouve donc `CMediusers`. Résultat mesuré : la purge ABOUTIT, et ne laisse
     *    RIEN — ni ligne `user_action`, ni compte `CUser`, ni trace.
     *
     * ⇒ CONCLUSION, ET ELLE EST PLUS FAIBLE QUE MES DEUX PRÉCÉDENTES : le défaut du 5 octobre EST
     *   réel — sa sortie existe et nomme sa cause — et il NE SE REPRODUIT PAS aujourd'hui, sur
     *   aucune des deux classes. Son statut n'est donc ni « réfuté » ni « confirmé » : il est
     *   INCONNU. Quelque chose de l'état du 5 octobre différait, et je ne sais pas quoi.
     *
     * CE TEST SERT DONC DE CLIQUET, PAS DE PREUVE : il fixe le comportement observé aujourd'hui.
     * S'il se met à échouer, le défaut est revenu et on aura cette fois une reproduction — ce qui
     * est exactement ce qui manque pour traiter #179.
     *
     * @throws SeedException
     */
    public function testAMediuserWithAnActionPurgesCompletelyToday(): void
    {
        $practitioner = $this->traceAMediuserWithAnAction();
        $id           = (string)$practitioner->_id;

        $purger = new SeedPurger(self::MARKER);

        $this->assertSame(1, $purger->count(), 'La trace doit exister avant la purge.');
        $purger->purge();

        $this->assertSame(
            [],
            $purger->failures(),
            "Comportement observé le 2026-10-07 : la purge aboutit. Si cette assertion tombe, le"
            . ' défaut du 2026-10-05 est revenu — et on en a enfin une reproduction.'
        );

        // ⚠ ET ELLE NE DOIT RIEN LAISSER DERRIÈRE, ce qui est le contrôle qui compte vraiment.
        // Une purge qui supprimerait le praticien en laissant son compte `CUser` et sa ligne
        // `user_action` se déclarerait réussie tout en produisant des orphelins — un échec pire que
        // le refus, parce qu'il est silencieux. Mesuré : zéro des trois.
        $this->assertSame(
            [],
            (new CUserAction())->loadIds(['user_id' => "= '{$id}'"]),
            "Aucune ligne user_action ne doit survivre : elle pointerait un compte supprimé."
        );

        $this->assertSame(
            [],
            (new CUser())->loadIds(['user_id' => "= '{$id}'"]),
            'Aucun compte CUser ne doit survivre au praticien qui le portait.'
        );

        $this->assertSame(0, $purger->count(), 'La trace doit partir avec sa cible.');
    }

    /**
     * Écrit un praticien, une action sur son compte, et la trace du semis.
     *
     * @throws SeedException
     */
    private function traceAMediuserWithAnAction(): CMediusers
    {
        $function = (new CFunctions())->loadList([], null, 1);
        $function = reset($function);

        if (!$function || !$function->_id) {
            $this->markTestSkipped("Aucune fonction en base : le praticien n'est pas fabricable.");
        }

        $practitioner                   = new CMediusers();
        $practitioner->function_id      = $function->_id;
        $practitioner->_user_first_name = 'Sonde';
        $practitioner->_user_last_name  = 'Purge';
        $practitioner->_user_username   = 'purge_probe_med_' . substr(md5(self::MARKER), 0, 8);
        $practitioner->_user_password   = 'Sonde-Purge-2026!';
        $practitioner->_user_type       = 3;
        $practitioner->actif            = '1';

        if ($msg = $practitioner->store()) {
            throw new SeedException("Praticien du test non écrit : {$msg}");
        }

        $this->mediusers[] = $practitioner;

        $action                  = new CUserAction();
        $action->user_id         = $practitioner->_id;
        $action->object_class_id = $practitioner->getObjectClassID();
        $action->object_id       = $practitioner->_id;
        $action->date            = CMbDT::dateTime();
        $action->type            = 'create';

        if ($msg = $action->store()) {
            throw new SeedException("Action du test non écrite : {$msg}");
        }

        $reference                 = new CFixturesReference();
        $reference->fixtures_class = self::MARKER;
        $reference->object_class   = $practitioner->_class;
        $reference->object_id      = $practitioner->_id;

        if ($msg = $reference->store()) {
            throw new SeedException("Trace du test non écrite : {$msg}");
        }

        return $practitioner;
    }

    /**
     * Écrit un compte, éventuellement une action de connexion, et la trace du semis.
     *
     * @throws SeedException
     */
    private function traceAUserWithAnAction(bool $with_action = true): CUser
    {
        $group = CGroups::loadCurrent();

        if (!$group->_id) {
            $this->markTestSkipped("Aucun établissement courant : la cible n'est pas fabricable.");
        }

        $user                  = new CUser();
        $user->user_username   = 'purge_probe_' . substr(md5(self::MARKER . $with_action), 0, 10);
        $user->user_first_name = 'Sonde';
        $user->user_last_name  = 'Purge';

        // ⚠ PAS DE `user_type`, ET SURTOUT PAS 1 : c'est le type ADMINISTRATEUR, et `check()` le
        // refuse à un compte qui n'en est pas un — « You are not allowed to modify an admin user ».
        // Ma première version le posait et les trois tests tombaient à la fabrication. Le semis ne
        // crée aucun administrateur non plus.

        if ($msg = $user->store()) {
            throw new SeedException("Compte du test non écrit : {$msg}");
        }

        $this->users[] = $user;

        if ($with_action) {
            // UNE ACTION « create » SUR LUI-MÊME, ce qui est la forme la plus proche de la trace
            // laissée par une connexion : `object_class`/`object_id` pointent un objet réel, et
            // `user_id` le compte observé.
            // ⚠ `object_class_id` ET NON `object_class` : le champ stocké est une RÉFÉRENCE vers
            // `CObjectClass`, dont `object_class` n'est que la méta lisible. Poser la chaîne laisse
            // la référence nulle et `check()` refuse — « CUserAction-object_class_id : Ne peut pas
            // avoir une valeur nulle ». `CStoredObject::getObjectClassID()` est la voie que le
            // socle emprunte lui-même pour écrire ses propres actions.
            $action                  = new CUserAction();
            $action->user_id         = $user->_id;
            $action->object_class_id = $user->getObjectClassID();
            $action->object_id       = $user->_id;
            $action->date         = CMbDT::dateTime();
            $action->type         = 'create';

            if ($msg = $action->store()) {
                throw new SeedException("Action du test non écrite : {$msg}");
            }
        }

        $reference                 = new CFixturesReference();
        $reference->fixtures_class = self::MARKER;
        $reference->object_class   = $user->_class;
        $reference->object_id      = $user->_id;

        if ($msg = $reference->store()) {
            throw new SeedException("Trace du test non écrite : {$msg}");
        }

        return $user;
    }
}
