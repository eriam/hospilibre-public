<?php

/**
 * @package Hospilibre\Cim10
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Cim10\Oms;

use Ox\Core\CAppUI;
use Ox\Core\Import\CExternalDataSourceImport;
use Hospilibre\Cim10\CImportCim10;

class CCIM10OmsImport extends CExternalDataSourceImport
{
    public const SOURCE_NAME = 'cim10';
    public const DATA_DIR    = '../../base';

    public const OMS_IMPORT_FILES = ['cim10.tar.gz', 'cim10.sql'];

    public const FILES = [
        'cim10_oms' => self::OMS_IMPORT_FILES,
    ];

    public const OMS_UPDATE_ARCHIVE  = 'cim10_modifs.tar.gz';
    public const OMS_UPDATE_CSV_FILE = 'cim10_modifs.csv';

    /**
     * Colonnes de `libelle` qui reçoivent le libellé de l'archive de mises à jour.
     *
     * `CImportCim10::addLabel()` écrit la MÊME valeur dans ces trois-là. Les autres colonnes de la
     * table (`EN_OMS`, `GE_DIMDI`, `GE_AUTO`) appartiennent au dump de base et ne reçoivent rien
     * d'ici : on ne modifie que ce qu'on écrit.
     */
    private const LABEL_COLUMNS = ['libelle', 'FR_OMS', 'FR_CHRONOS'];

    public function __construct()
    {
        parent::__construct(
            self::SOURCE_NAME,
            self::DATA_DIR,
            self::FILES
        );
    }

    public function importDatabase(?array $types = [], string $action = 'all'): bool
    {
        switch ($action) {
            case 'import':
                return parent::importDatabase($types);
            case 'update':
                return $this->importDataFromCsv();
            case 'all':
            default:
                $result = parent::importDatabase($types);
                if (false === $result) {
                    return false;
                }
                return $this->importDataFromCsv();
        }
    }

    /**
     * ÉLARGIT LES COLONNES DE LIBELLÉ AVANT D'Y ÉCRIRE.
     *
     * LE DÉFAUT : `cim10_modifs.csv` porte 116 champs de plus de 255 caractères, le plus long faisant
     * 1 012 caractères ; après découpe et nettoyage, **6 libellés écrits** dépassent encore 255
     * caractères, jusqu'à **523** (mesuré en base après import), alors que le dump de base déclare
     * `libelle`.`libelle`, `FR_OMS` et `FR_CHRONOS` en `varchar(255)`. Les deux issues étaient
     * mauvaises, et c'est ce qui rendait ce défaut coûteux :
     *
     *  - `sql_mode` STRICT, qui est le DÉFAUT de MariaDB : erreur 1406 « Data too long », et l'import
     *    meurt. Pire, il meurt APRÈS avoir peuplé les 21 tables, donc il laisse une base que le
     *    passage suivant saute en la déclarant « peuplée, conservée » : un import partiel devient
     *    indistinguable d'un import complet.
     *  - `sql_mode` relâché : les 116 libellés sont TRONQUÉS EN SILENCE à 255 caractères. Aucune
     *    requête n'échoue, donc aucune détection ne peut le voir — pas même le contrôle de complétude
     *    d'`ox-install:externals`, qui vérifie qu'aucune requête n'a échoué.
     *
     * Le dump de base n'est pas en cause et n'est pas corrigé ici : il vient de l'OMS et il est juste
     * pour ce qu'il contient. C'est cet importeur qui y ajoute des données plus longues, donc c'est à
     * lui d'élargir la colonne avant de les écrire.
     *
     * TEXT et non `varchar(600)` : 523 caractères est le maximum de la donnée LIVRÉE AUJOURD'HUI, et
     * une borne calculée sur la donnée du jour se fait rattraper par la mise à jour suivante. Aucune
     * de ces trois colonnes ne porte d'index (seuls `LID` et `SID` en ont), donc le passage en TEXT
     * ne coûte rien ici.
     *
     * Rejoué à chaque import, et idempotent par construction : le dump de base recrée la table en
     * `varchar(255)` juste avant, et `MODIFY` pose un type absolu au lieu de l'incrémenter.
     */
    protected function widenLabelColumns(): void
    {
        if (!$this->ds) {
            return;
        }

        foreach (self::LABEL_COLUMNS as $column) {
            $this->ds->exec("ALTER TABLE `libelle` MODIFY `{$column}` TEXT NOT NULL");
        }
    }

    public function importDataFromCsv(): bool
    {
        $this->extractData(self::OMS_UPDATE_ARCHIVE);

        $this->widenLabelColumns();

        (new CImportCim10(
            $this->getTmpDir() . self::OMS_UPDATE_CSV_FILE,
            $this->getSource(),
            $this
        ))->run();

        return true;
    }
}
