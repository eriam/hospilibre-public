<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Tests\Unit\Seed;

use Ox\Cli\Seed\FictitiousNames;
use Ox\Cli\Seed\SeedException;
use Ox\Cli\Seed\SeedRandom;
use PHPUnit\Framework\TestCase;

/**
 * Aucune base de données n'est nécessaire.
 */
class FictitiousNamesTest extends TestCase
{
    public function testListsAreNotEmpty(): void
    {
        $this->assertNotEmpty(FictitiousNames::surnames());
        $this->assertNotEmpty(FictitiousNames::firstNames(FictitiousNames::SEX_FEMALE));
        $this->assertNotEmpty(FictitiousNames::firstNames(FictitiousNames::SEX_MALE));
    }

    /**
     * Un doublon réduirait silencieusement la diversité et biaiserait les tirages en faveur du
     * nom répété.
     */
    public function testNoDuplicates(): void
    {
        foreach ($this->allLists() as $label => $list) {
            $this->assertSame(
                count($list),
                count(array_unique($list)),
                "Doublon dans la liste '{$label}' : " . implode(', ', array_diff_assoc($list, array_unique($list)))
            );
        }
    }

    /**
     * Ces chaînes partent en base et s'affichent dans l'interface : un espace parasite ou un
     * encodage cassé se retrouverait tel quel dans un dossier patient.
     */
    public function testEntriesAreCleanUtf8(): void
    {
        foreach ($this->allLists() as $label => $list) {
            foreach ($list as $name) {
                $this->assertSame($name, trim($name), "Espace parasite dans '{$label}' : '{$name}'");
                $this->assertNotSame('', $name, "Entrée vide dans '{$label}'");
                $this->assertTrue(
                    mb_check_encoding($name, 'UTF-8'),
                    "Encodage non UTF-8 dans '{$label}' : '{$name}'"
                );
                $this->assertMatchesRegularExpression(
                    "/^[A-ZÀÂÄÇÉÈÊËÎÏÔÖÙÛÜ][a-zàâäçéèêëîïôöùûüA-Z'\- ]+$/u",
                    $name,
                    "Forme inattendue dans '{$label}' : '{$name}'"
                );
            }
        }
    }

    /**
     * Le nombre de combinaisons borne le moment où les homonymies parfaites deviennent
     * inévitables. Il doit rester largement au-dessus du profil `medium` (800 patients), sans
     * quoi un range de démonstration serait peuplé de doublons.
     */
    public function testCombinationsCoverTheUsualScales(): void
    {
        $this->assertGreaterThan(5000, FictitiousNames::combinations(FictitiousNames::SEX_FEMALE));
        $this->assertGreaterThan(5000, FictitiousNames::combinations(FictitiousNames::SEX_MALE));
    }

    public function testUnknownSexIsRejected(): void
    {
        $this->expectException(SeedException::class);
        FictitiousNames::firstNames('x');
    }

    public function testVersionIsDeclared(): void
    {
        $this->assertGreaterThanOrEqual(1, FictitiousNames::VERSION);
    }

    /**
     * Le jeu de noms n'a d'intérêt que s'il se combine de façon reproductible avec le
     * générateur : à graine égale, la même identité doit sortir.
     */
    public function testDrawingAnIdentityIsReproducible(): void
    {
        $identity = static function (int $seed): string {
            $random = new SeedRandom($seed);
            $sex     = $random->chanceInThousand(500)
                ? FictitiousNames::SEX_FEMALE
                : FictitiousNames::SEX_MALE;

            return $random->pick(FictitiousNames::firstNames($sex)) . ' ' . $random->pick(FictitiousNames::surnames());
        };

        $this->assertSame($identity(1234), $identity(1234));
        $this->assertNotSame($identity(1234), $identity(1235));
    }

    /**
     * @return array<string, string[]>
     */
    private function allLists(): array
    {
        return [
            'patronymes'       => FictitiousNames::surnames(),
            'prénoms féminins' => FictitiousNames::firstNames(FictitiousNames::SEX_FEMALE),
            'prénoms masculins' => FictitiousNames::firstNames(FictitiousNames::SEX_MALE),
        ];
    }

    /**
     * LA PONDÉRATION PAR LA FRÉQUENCE RÉELLE, et pourquoi elle mérite un test.
     *
     * Sans elle, le tirage est UNIFORME sur les 5 022 prénoms de l'INSEE : « Randy » (1 840
     * naissances depuis 1900) sort aussi souvent que « Marie » (2 232 235), et la cohorte se
     * remplit de prénoms rares qui ne composent pas une population française. Le défaut a été vu à
     * l'écran, pas par un test — d'où celui-ci.
     *
     * CE QU'IL VÉRIFIE N'EST PAS UN CHIFFRE PRÉCIS mais un ORDRE DE GRANDEUR : les poids viennent
     * d'un millésime INSEE qui bougera, et un test sur la valeur exacte casserait à la première
     * mise à jour du fichier en apprenant seulement que le fichier a changé. Qu'un prénom très
     * courant pèse au moins cent fois plus qu'un prénom rare, en revanche, restera vrai tant que la
     * pondération existe — et deviendra faux à la seconde où on la retirerait.
     *
     * @throws SeedException
     */
    public function testFirstNamesAreWeightedByRealFrequency(): void
    {
        $weights = FictitiousNames::firstNameWeights(FictitiousNames::SEX_MALE);

        $this->assertNotSame(
            [],
            $weights,
            "Le jeu de personas doit porter des fréquences : sans elles le tirage redevient uniforme."
        );

        $this->assertArrayHasKey('Jean', $weights);
        $this->assertArrayHasKey('Randy', $weights);

        $this->assertGreaterThan(
            100 * $weights['Randy'],
            $weights['Jean'],
            "« Jean » doit peser au moins cent fois « Randy » : c'est l'ordre de grandeur réel, et"
            . ' le tirage uniforme leur donnait le même poids.'
        );

        // Les poids doivent être des entiers positifs : `SeedRandom::pickWeighted()` refuse le
        // contraire, et il refuserait AU MILIEU du semis, après des milliers d'écritures.
        foreach ($weights as $name => $weight) {
            $this->assertIsInt($weight, "Poids non entier pour '{$name}'");
            $this->assertGreaterThan(0, $weight, "Poids nul ou négatif pour '{$name}'");
        }
    }

    /**
     * Les listes embarquées ne portent PAS de fréquences, et l'appelant doit pouvoir le voir.
     *
     * C'est ce qui permet à une source sans poids — un fichier imposé par un exploitant — de rester
     * employable : un tableau vide veut dire « tire uniformément », pas « erreur ».
     *
     * @throws SeedException
     */
    public function testAnImposedFileCarriesNoWeights(): void
    {
        $path = sys_get_temp_dir() . '/identites-' . uniqid('', false) . '.csv';
        file_put_contents($path, "nom,prenom_f,prenom_m\nDupont,Marie,Jean\n");

        FictitiousNames::useFile($path);

        try {
            $this->assertSame([], FictitiousNames::firstNameWeights(FictitiousNames::SEX_MALE));
            $this->assertSame(['Jean'], FictitiousNames::firstNames(FictitiousNames::SEX_MALE));
        } finally {
            FictitiousNames::useFile(null);
            @unlink($path);
        }
    }

    /**
     * LES PATRONYMES SONT PONDÉRÉS AUSSI, et le format a changé pour cela.
     *
     * Le fichier portait un patronyme par ligne ; il porte maintenant « Patronyme;porteurs ». Un
     * lecteur resté sur l'ancien format écrirait « Martin;31781 » dans le dossier du patient — un
     * défaut qui ne casse rien et qu'on ne verrait qu'à l'écran. Ce test l'attrape.
     *
     * @throws SeedException
     */
    public function testSurnamesAreWeightedAndFreeOfTheirSeparator(): void
    {
        $weights = FictitiousNames::surnameWeights();

        $this->assertNotSame([], $weights, 'Les patronymes doivent porter leur nombre de porteurs.');
        $this->assertArrayHasKey('Martin', $weights);

        foreach (FictitiousNames::surnames() as $surname) {
            $this->assertStringNotContainsString(
                ';',
                $surname,
                "Le patronyme '{$surname}' contient le séparateur : le poids a été lu comme une"
                . ' partie du nom.'
            );
        }

        // « Martin » est le patronyme le plus porté en France : il doit dominer la médiane, sinon
        // la pondération ne fait rien. L'ordre de grandeur suffit — la valeur suivra la source.
        $sorted = array_values($weights);
        sort($sorted);
        $median = $sorted[(int)(count($sorted) / 2)];

        $this->assertGreaterThan(
            10 * $median,
            $weights['Martin'],
            "« Martin » doit peser au moins dix fois la médiane : le tirage uniforme leur donnait"
            . ' la même chance.'
        );
    }

    /**
     * L'EMPREINTE DOIT CHANGER QUAND LE JEU D'IDENTITÉS CHANGE, et c'est tout ce qu'on lui demande.
     *
     * POURQUOI ELLE EXISTE. La commande imprimait « pour le rejouer à l'identique » suivi des seuls
     * paramètres. C'était faux : le jeu d'identités est un fichier du dépôt, donc une livraison peut
     * le changer entre deux semis de mêmes paramètres. Mesuré sur la production le 2026-10-04 —
     * 16 560 noms différents sur 8 324 patients, à décor, graine et instant identiques, les dates de
     * naissance ne bougeant qu'à la marge.
     *
     * ET POURQUOI ELLE EST CALCULÉE et non déclarée : la constante VERSION portait déjà la règle
     * « incrémenter à chaque modification du contenu », et le contenu a été modifié deux fois le
     * même jour sans qu'elle bouge. Une empreinte qu'on ne peut pas oublier valait mieux qu'une
     * règle qu'on oublie.
     *
     * @throws SeedException
     */
    public function testTheFingerprintTracksTheIdentitySet(): void
    {
        $shipped = FictitiousNames::fingerprint();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $shipped);

        $path = sys_get_temp_dir() . '/identites-empreinte-' . uniqid('', false) . '.csv';
        file_put_contents($path, "nom,prenom_f,prenom_m\nDupont,Marie,Jean\n");

        FictitiousNames::useFile($path);

        try {
            $imposed = FictitiousNames::fingerprint();

            $this->assertNotSame(
                $shipped,
                $imposed,
                "Changer de jeu d'identités DOIT changer l'empreinte : sans cela, deux cohortes"
                . ' incomparables porteraient la même marque et on conclurait à une dérive de'
                . ' données là où il y a eu un changement de source.'
            );

            // Stable à source égale : une empreinte qui bougerait d'un appel à l'autre ne
            // permettrait aucune comparaison.
            $this->assertSame($imposed, FictitiousNames::fingerprint());
        } finally {
            FictitiousNames::useFile(null);
            @unlink($path);
        }

        $this->assertSame($shipped, FictitiousNames::fingerprint(), 'Le rétablissement doit rendre la même empreinte.');
    }
}
