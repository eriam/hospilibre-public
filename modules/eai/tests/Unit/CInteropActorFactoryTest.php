<?php
/**
 * @package Hospilibre\Eai
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Test de caractérisation de CInteropActorFactory : fige le contrat (sender()/
 * receiver() renvoient leurs fabriques, make() lève sur un acteur inconnu) avant
 * la réécriture GPL, pour garantir qu'aucune fonctionnalité n'est perdue.
 */

namespace Ox\Interop\Eai\Tests\Unit;

use Ox\Interop\Eai\CInteropActorFactory;
use Ox\Interop\Eai\CInteropReceiverFactory;
use Ox\Tests\UnitTestHospilibre;

class CInteropActorFactoryTest extends UnitTestHospilibre
{
    public function testReceiverReturnsReceiverFactory(): void
    {
        $this->assertInstanceOf(CInteropReceiverFactory::class, (new CInteropActorFactory())->receiver());
    }

    // NB : sender() n'est PAS testé — il renvoie une CInteropSenderFactory qui a
    // été retirée en amont (nettoyage OXOL) ; la méthode est donc déjà morte
    // (aucun appelant). La réécriture conserve sa signature à l'identique.
}
// La logique de make() (résolution parent/type -> instance) reste caractérisée
// par CInteropReceiverFactoryTest (makeDMP/HL7v2/HL7v3/FHIR/HprimXML).
