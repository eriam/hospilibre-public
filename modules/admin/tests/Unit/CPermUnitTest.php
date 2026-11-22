<?php

/**
 * @package Hospilibre\Admin\Tests
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Hospilibre\Admin\Tests\Unit;

use Hospilibre\Admin\CPermUnit;
use Hospilibre\Admin\CUser;
use Hospilibre\Hospi\CObservationMedicale;
use Ox\Tests\UnitTestHospilibre;

/**
 * Les décisions du périmètre qui NE DÉPENDENT PAS des données.
 *
 * CE QUE CE FICHIER GARDE, et pourquoi ce ne sont pas des broutilles : trois décisions de
 * `CPermUnit` ont l'air d'être des détails d'écriture et sont en réalité ce qui fait la différence
 * entre un droit qui protège et un droit inopérant — ou, pire, entre un droit qui restreint et un
 * droit qui enferme. Elles ont toutes les trois la propriété d'être INVISIBLES en service : rien
 * dans l'application ne signale un périmètre éteint, ni un refus porté sur une classe qui n'aurait
 * pas dû l'être.
 *
 * LE COMPORTEMENT SUR DONNÉES RÉELLES EST AILLEURS, dans `ox-perms:scope`, qui mesure ce qu'un
 * agent voit et croise les unités. Les deux sont complémentaires : un test ne peut pas dire si le
 * découpage est vivable, et un rapport ne peut pas tourner dans l'intégration continue sur un
 * établissement qui n'existe pas encore.
 */
class CPermUnitTest extends UnitTestHospilibre
{
    protected function tearDown(): void
    {
        CPermUnit::resetCache();

        parent::tearDown();
    }

    /**
     * LA DÉCISION LA PLUS COÛTEUSE DU FICHIER.
     *
     * Les valeurs par défaut des modules ne sont pas lues à l'exécution : `CConfigDist` les compile
     * dans `includes/config_dist.php`, qui est ignoré par git et monté EN LECTURE SEULE depuis
     * l'hôte sur les instances en service. Une clé nouvelle n'y figure donc pas — et si l'absence
     * valait « inactif », le périmètre serait éteint partout où il compte, silencieusement.
     * Mesuré : avant cette règle, `ox-perms:scope` rapportait zéro refus et un verdict satisfait.
     */
    public function testAbsentConfigurationMeansActive(): void
    {
        $previous = $GLOBALS['dPconfig']['admin']['CPermUnit'] ?? null;

        unset($GLOBALS['dPconfig']['admin']['CPermUnit']);

        try {
            $this->assertTrue(
                CPermUnit::isActive(),
                "Sans configuration, le périmètre doit être ACTIF : une clé absente est le cas "
                . "NORMAL sur une instance déployée, pas une demande de désactivation."
            );
        } finally {
            if ($previous !== null) {
                $GLOBALS['dPconfig']['admin']['CPermUnit'] = $previous;
            }
        }
    }

    /**
     * Et l'extinction doit rester possible, sinon le levier ne sert à rien.
     */
    public function testExplicitZeroDeactivates(): void
    {
        $previous = $GLOBALS['dPconfig']['admin']['CPermUnit'] ?? null;

        $GLOBALS['dPconfig']['admin']['CPermUnit'] = ['active' => '0'];

        try {
            $this->assertFalse(CPermUnit::isActive());

            $observation            = new CObservationMedicale();
            $observation->_id       = 1;
            $observation->sejour_id = 1;

            $this->assertFalse(
                CPermUnit::denies($observation, new CUser()),
                "Périmètre éteint : il ne doit plus rien refuser, même sur une classe qu'il couvre."
            );
        } finally {
            if ($previous === null) {
                unset($GLOBALS['dPconfig']['admin']['CPermUnit']);
            } else {
                $GLOBALS['dPconfig']['admin']['CPermUnit'] = $previous;
            }
        }
    }

    /**
     * LISTE BLANCHE : ce qui n'est pas nommé échappe au périmètre.
     *
     * Le risque inverse — une liste noire, ou un prédicat qui s'appliquerait à tout `CMbObject`
     * portant un `sejour_id` — est que chaque classe ajoutée au logiciel se retrouve filtrée par
     * une règle écrite sans la connaître. Le symptôme serait un refus inexplicable sur un écran
     * sans rapport avec le dossier de soins.
     */
    public function testUnlistedClassIsNeverDenied(): void
    {
        $this->assertSame(
            ['CObservationMedicale', 'CTransmissionMedicale'],
            CPermUnit::SCOPED_CLASSES,
            "Si ce lot change, c'est une décision métier : elle doit être explicite, pas héritée "
            . "d'une modification de passage."
        );

        $user = new CUser();

        $this->assertFalse(
            CPermUnit::denies($user, $user),
            "Une classe hors du lot ne doit jamais être refusée par le périmètre."
        );
    }

    /**
     * UN OBJET NON ENREGISTRÉ n'a pas de séjour à situer.
     *
     * La création relève du droit sur le module — refuser ici reviendrait à interdire d'écrire une
     * observation à l'agent même qui est au lit du patient, puisque l'objet qu'il s'apprête à
     * enregistrer n'a encore ni identifiant ni séjour.
     */
    public function testUnstoredObjectIsNeverDenied(): void
    {
        $observation = new CObservationMedicale();

        $this->assertFalse(
            CPermUnit::denies($observation, new CUser()),
            "Un objet sans identifiant ne peut pas être situé : le périmètre doit se taire."
        );
    }
}
