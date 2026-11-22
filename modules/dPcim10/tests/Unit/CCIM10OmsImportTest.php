<?php

/**
 * @package Hospilibre\Cim10
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Hospilibre\Cim10\Tests\Unit;

use Hospilibre\Cim10\Oms\CCIM10OmsImport;
use PHPUnit\Framework\TestCase;

/**
 * LA TABLE DES LIBELLÉS NE POUVAIT PAS CONTENIR SES PROPRES LIBELLÉS.
 *
 * `cim10_modifs.csv` — l'archive de mises à jour de l'OMS, appliquée après le dump de base — porte
 * **116 champs de plus de 255 caractères**, le plus long faisant **1 012 caractères**. Après découpe
 * et nettoyage, **6 libellés effectivement écrits** dépassent encore 255 caractères, jusqu'à **523**
 * (mesuré en base après import). Or `CImportCim10::addLabel()` les écrit dans `libelle`.`libelle`,
 * `FR_OMS` et `FR_CHRONOS`, que le dump de base déclare en `varchar(255)`.
 *
 * Les deux issues étaient mauvaises, et c'est ce qui rend ce défaut coûteux :
 *
 * - `sql_mode` STRICT (le défaut de MariaDB) : erreur 1406 « Data too long », l'import **meurt** —
 *   et il meurt après avoir peuplé les 21 tables, donc il laisse une base que le passage suivant
 *   saute en la déclarant « peuplée, conservée » ;
 * - `sql_mode` relâché : les libellés trop longs sont **tronqués en silence** à 255 caractères. Aucune
 *   requête n'échoue, donc aucune détection ne peut le voir.
 *
 * Le dump de base ne peut pas être corrigé ici (il vient de l'OMS, et il est juste pour ce qu'il
 * contient) : c'est l'importeur qui ajoute des données plus longues, donc c'est à lui d'élargir la
 * colonne avant de les écrire.
 *
 * Aucune base de données n'est nécessaire : la source est remplacée par une sonde qui enregistre les
 * requêtes.
 */
class CCIM10OmsImportTest extends TestCase
{
    /**
     * Longueur du plus long libellé RÉELLEMENT ÉCRIT, mesurée en base après import.
     *
     * À ne pas confondre avec le plus long CHAMP du CSV (1 012 caractères) : les champs sont découpés
     * et nettoyés avant écriture. C'est la valeur écrite qui décide de la largeur nécessaire.
     */
    private const LONGEST_WRITTEN_LABEL = 523;

    /**
     * LE TEST QUI MANQUAIT : les trois colonnes qui reçoivent le libellé doivent être élargies.
     *
     * `CImportCim10::addLabel()` écrit la MÊME valeur dans les trois. En oublier une laisserait le
     * défaut intact sur cette colonne, avec exactement la même panne.
     */
    public function testTheThreeColumnsThatReceiveALabelAreWidened(): void
    {
        $recorder = new CQueryRecorder();
        (new COmsImportProbe($recorder))->widen();

        foreach (['libelle', 'FR_OMS', 'FR_CHRONOS'] as $column) {
            $this->assertTrue(
                $recorder->altered('libelle', $column),
                "La colonne {$column} reçoit un libellé et doit être élargie"
            );
        }
    }

    /**
     * Le type retenu doit contenir le plus long libellé écrit. Un `varchar(255)` ne peut pas, et un
     * type choisi « avec un peu de marge » se ferait rattraper à la prochaine mise à jour de l'OMS.
     */
    public function testTheWidenedTypeHoldsTheLongestWrittenLabel(): void
    {
        $recorder = new CQueryRecorder();
        (new COmsImportProbe($recorder))->widen();

        foreach ($recorder->queries as $query) {
            $this->assertMatchesRegularExpression(
                '/MODIFY .*\bTEXT\b/i',
                $query,
                'TEXT contient 65 535 octets, donc les ' . self::LONGEST_WRITTEN_LABEL
                . ' caractères du plus long libellé écrit, et ceux d\'une mise à jour future'
            );
        }
    }

    /**
     * LA BORNE : les colonnes que cet importeur n'écrit JAMAIS ne sont pas touchées.
     *
     * `EN_OMS`, `GE_DIMDI` et `GE_AUTO` appartiennent au dump de base et ne reçoivent rien de
     * `cim10_modifs.csv`. Les élargir serait un changement de schéma que rien ne demande — et la
     * règle « on ne modifie que ce qu'on écrit » est ce qui rend ce correctif défendable.
     */
    public function testColumnsThisImporterNeverWritesAreLeftAlone(): void
    {
        $recorder = new CQueryRecorder();
        (new COmsImportProbe($recorder))->widen();

        foreach (['EN_OMS', 'GE_DIMDI', 'GE_AUTO'] as $column) {
            $this->assertFalse(
                $recorder->altered('libelle', $column),
                "{$column} ne reçoit aucun libellé de l'archive de mises à jour"
            );
        }
    }

    /**
     * L'élargissement est rejoué à chaque import — le dump de base recrée la table en `varchar(255)`
     * juste avant. Il doit donc être idempotent, et il l'est par construction : `MODIFY` pose un type
     * absolu, il ne l'incrémente pas.
     */
    public function testWideningTwiceIssuesTheSameStatements(): void
    {
        $first = new CQueryRecorder();
        (new COmsImportProbe($first))->widen();

        $second = new CQueryRecorder();
        $probe  = new COmsImportProbe($second);
        $probe->widen();
        $probe->widen();

        $this->assertSame(
            array_unique($first->queries),
            array_unique($second->queries),
            'Un second passage ne doit rien changer d\'autre'
        );
    }
}

/**
 * Rend publique la seule étape en cause, et permet d'injecter la source.
 */
class COmsImportProbe extends CCIM10OmsImport
{
    /**
     * @param object $ds Source réduite à ce que consomme l'élargissement
     */
    public function __construct(object $ds)
    {
        parent::__construct();

        $this->ds = $ds;
    }

    public function widen(): void
    {
        $this->widenLabelColumns();
    }
}

/**
 * Source de données réduite à `exec()`, qui enregistre les requêtes au lieu de les exécuter.
 */
class CQueryRecorder
{
    /** @var string[] */
    public $queries = [];

    public function exec(string $query): bool
    {
        $this->queries[] = $query;

        return true;
    }

    /** La requête enregistrée modifie-t-elle cette colonne de cette table ? */
    public function altered(string $table, string $column): bool
    {
        foreach ($this->queries as $query) {
            if (
                stripos($query, "ALTER TABLE `{$table}`") !== false
                && strpos($query, "`{$column}`") !== false
            ) {
                return true;
            }
        }

        return false;
    }
}
