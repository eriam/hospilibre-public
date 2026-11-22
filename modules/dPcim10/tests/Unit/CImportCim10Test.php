<?php

/**
 * @package Hospilibre\Cim10
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Hospilibre\Cim10\Tests\Unit;

use Hospilibre\Cim10\CImportCim10;
use PHPUnit\Framework\TestCase;

/**
 * UN INSERT QUI OMET UNE COLONNE OBLIGATOIRE NE MARCHE QU'EN MODE RELÂCHÉ.
 *
 * `memo`.`EN_OMS` et `memo`.`GE_DIMDI` sont `TEXT NOT NULL` — et un TEXT ne peut pas porter de valeur
 * par défaut en MySQL. L'INSERT des mémos ne les fournissait pas :
 *
 *  - `sql_mode` relâché : MySQL y met une chaîne vide, en silence. C'est ce qui se passe depuis
 *    toujours, et c'est pourquoi personne ne l'avait vu.
 *  - `sql_mode` STRICT, qui est le DÉFAUT de MariaDB : erreur 1364 « Field 'EN_OMS' doesn't have a
 *    default value », et l'import de la CIM-10 meurt.
 *
 * Ce test fixe la règle plutôt que le symptôme : **l'INSERT doit nommer toutes les colonnes que le
 * schéma exige**, sans dépendre de la complaisance du serveur. Les valeurs écrites restent les mêmes
 * qu'avant — une chaîne vide — pour ne rien changer au contenu importé.
 *
 * Aucune base de données n'est nécessaire : la construction de la requête est pure.
 */
class CImportCim10Test extends TestCase
{
    /**
     * Colonnes de `memo` que le schéma rend obligatoires : `NOT NULL` et sans valeur par défaut
     * possible (TEXT). Relevé sur une base réellement installée, pas déduit du dump.
     */
    private const MANDATORY_MEMO_COLUMNS = ['memo', 'FR_OMS', 'EN_OMS', 'GE_DIMDI'];

    public function testTheMemoInsertNamesEveryMandatoryColumn(): void
    {
        $query = CImportCim10::memoInsertQuery(42, 'Une note', '2026-09-30 08:00:00', 'OMS');

        foreach (self::MANDATORY_MEMO_COLUMNS as $column) {
            $this->assertStringContainsString(
                "`{$column}`",
                $query,
                "{$column} est NOT NULL sans défaut possible : l'omettre ne marche qu'en mode relâché"
            );
        }
    }

    /**
     * LE PIÈGE en ajoutant des colonnes à un INSERT écrit à la main : oublier la valeur
     * correspondante. MySQL rejette alors la requête entière, et on aurait remplacé une panne par
     * une autre.
     */
    public function testTheMemoInsertProvidesAsManyValuesAsColumns(): void
    {
        $query = CImportCim10::memoInsertQuery(42, 'Une note', '2026-09-30 08:00:00', 'OMS');

        $this->assertSame(
            1,
            preg_match('/\(([^)]*)\)\s*VALUES\s*\(([^;]*)\);/s', $query, $matches),
            'La requête doit avoir une liste de colonnes et une liste de valeurs'
        );

        $this->assertSame(
            count(explode(',', $matches[1])),
            count(explode(',', $matches[2])),
            'Autant de valeurs que de colonnes'
        );
    }

    /**
     * Le contenu importé ne doit PAS changer : la note va dans `memo` et `FR_OMS`, comme avant, et les
     * deux colonnes ajoutées reçoivent la chaîne vide — exactement ce que le serveur relâché y mettait
     * tout seul. Un correctif de portabilité ne doit pas modifier les données.
     */
    public function testTheAddedColumnsCarryTheSameEmptyStringTheServerUsedToSupply(): void
    {
        $query = CImportCim10::memoInsertQuery(42, 'Une note', '2026-09-30 08:00:00', 'OMS');

        $this->assertStringContainsString("'Une note'", $query, 'La note reste écrite');
        $this->assertMatchesRegularExpression(
            "/''\s*,\s*''/",
            $query,
            'EN_OMS et GE_DIMDI reçoivent la chaîne vide, comme le faisait le mode relâché'
        );
    }
}
