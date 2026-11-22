<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Tests\Unit;

use Ox\Cli\Console\InstallExternals;
use Ox\Tests\UnitTestHospilibre;
use ReflectionMethod;

/**
 * UN DICTIONNAIRE ABSENT NE DOIT PAS POUVOIR PASSER POUR UN DICTIONNAIRE EN PLACE.
 *
 * Mesuré sur une installation neuve le 2026-10-03 : l'état d'une source se décidait sur
 * « la base cible contient-elle des tables ? ». Or les treize sources de dictionnaire de la
 * configuration livrée pointent toutes sur la base de l'APPLICATION. Le compte y trouvait les
 * 500 tables du schéma applicatif, que ox-modules:setup venait de créer, et la commande annonçait
 * « [OK] Dictionnaires externes à jour » en UNE SECONDE sans avoir importé un seul dictionnaire.
 *
 * Coût mesuré, deux semis identiques (graine 20260925, profil small) :
 *   sans dictionnaires : 5 295 objets · AUCUN acte CCAM détaillé · AUCUN diagnostic CIM-10
 *   avec               : 6 683 objets · 694 actes · 1 556 diagnostics
 * Un SIH pédagogique sans un acte codé ni un diagnostic — et le semis sortait en 0.
 *
 * Le critère s'appuie désormais sur les contrôles que chaque setup DÉCLARE par addDatasource().
 * Ce test porte sur la DÉCISION, isolée de la base : c'est elle qui était fausse, et elle doit
 * rester vérifiable sans dictionnaire de 174 Mo sous la main.
 */
class InstallExternalsDictionaryProbeTest extends UnitTestHospilibre
{
    /**
     * @return array{state: string, detail: string}
     */
    private function decide(int $passed, int $total): array
    {
        $method = new ReflectionMethod(InstallExternals::class, 'stateFromProbes');
        $method->setAccessible(true);

        return $method->invoke(null, $passed, $total);
    }

    /**
     * LE CŒUR DU CORRECTIF : aucun contrôle ne passe, donc le dictionnaire est absent — quel que
     * soit le nombre de tables que la base cible peut contenir par ailleurs.
     */
    public function testNoProbePassingMeansTheDictionaryIsMissing(): void
    {
        $this->assertSame(
            'vide',
            $this->decide(0, 4)['state'],
            "C'est le cas que l'ancien critère déclarait « peuplée, 610 tables »."
        );
    }

    public function testAllProbesPassingIsTheOnlyWayToBeDeclaredPopulated(): void
    {
        $this->assertSame('peuplée', $this->decide(4, 4)['state']);
        $this->assertSame(
            '4/4 contrôle(s) du dictionnaire',
            $this->decide(4, 4)['detail'],
            'Le détail doit dire SUR QUOI porte le verdict : un décompte de tables ne le disait pas.'
        );
    }

    /**
     * ÉTAT QUE L'ANCIEN CRITÈRE NE POUVAIT PAS VOIR. Un import interrompu laisse des tables
     * derrière lui : il était donc « peuplée », donc conservé, donc jamais repris.
     */
    public function testAPartiallyImportedDictionaryIsReimported(): void
    {
        $state = $this->decide(2, 4);

        $this->assertSame('incomplète', $state['state']);
        $this->assertNotSame(
            'peuplée',
            $state['state'],
            'Un dictionnaire à moitié écrit ne doit jamais être conservé en silence.'
        );
    }

    /**
     * Une source sans contrôle déclaré — `rpps_import` est la seule du dépôt — n'emprunte PAS le
     * nom d'un état vérifié. La base est conservée (réimporter 174 Mo à chaque démarrage coûterait
     * plus que le doute), mais le rapport ne prétend pas savoir.
     */
    public function testAnUnprobedSourceIsNeverCalledPopulated(): void
    {
        $state = $this->decide(0, 0);

        $this->assertSame('indéterminée', $state['state']);
        $this->assertNotSame('peuplée', $state['state']);
        $this->assertNotSame(
            'vide',
            $state['state'],
            "« Je ne sais pas » n'est pas « c'est absent » : l'un conserve, l'autre réimporte."
        );
    }

    /**
     * Les quatre états sont DISTINCTS. Les confondre deux à deux est exactement ce qui a produit le
     * faux « à jour » : « peuplée » recouvrait vérifiée, indéterminée et à moitié importée.
     */
    public function testTheFourStatesAreAllDistinct(): void
    {
        $states = [
            $this->decide(0, 0)['state'],
            $this->decide(0, 3)['state'],
            $this->decide(1, 3)['state'],
            $this->decide(3, 3)['state'],
        ];

        $this->assertCount(4, array_unique($states), 'Quatre situations, quatre noms.');
    }
}
