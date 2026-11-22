<?php

/**
 * @package Interop\Eai
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Eai;

use Ox\Core\CAppUI;
use Ox\Core\FileUtil\CCSVFile;
use Ox\Core\Import\CExternalDataSourceImport;

class CAsipImport extends CExternalDataSourceImport
{
    public const SOURCE_NAME = 'ASIP';
    public const DATA_DIR    = '../resources';

    public const FILES = [
        'asip' => [],
    ];

    public function __construct()
    {
        parent::__construct(
            self::SOURCE_NAME,
            self::DATA_DIR,
            self::FILES
        );
    }

    public function importDatabase(?array $types = []): bool
    {
        $ds   = $this->setSource();
        $path = $this->getDataDir();

        if (!$ds) {
            $this->addMessage(["Import impossible - Aucune source de données", UI_MSG_ERROR]);
            return false;
        }

        $files = glob("$path/*.jv");

        $lineCount = 0;
        foreach ($files as $_file) {
            $name  = basename($_file);
            $name  = substr($name, strpos($name, "_") + 1);
            $table = substr($name, 0, strrpos($name, "."));
            $table = strtolower($table);
            if (!$ds) {
                $this->addMessage(["Import impossible - Source non présente", UI_MSG_WARNING]);
                continue;
            }

            if ($ds->loadTable($table)) {
                $this->addMessage(["La table a déjé été importée - Import impossible", UI_MSG_WARNING]);
                continue;
            }

            $ds->query(
                "CREATE TABLE IF NOT EXISTS `$table` (
                `table_id` INT (11) UNSIGNED NOT NULL auto_increment PRIMARY KEY,
                `code` VARCHAR (255) NOT NULL,
                `oid` VARCHAR (255) NOT NULL,
                `libelle` VARCHAR (255) NOT NULL,
                INDEX (`table_id`)
              )/*! ENGINE=InnoDB */;"
            );

            $ds->query("DELETE FROM `$table`");

            $csv = new CCSVFile($_file);
            $csv->jumpLine(3);
            while ($line = $csv->readLine()) {
                [$oid, $code, $libelle] = $line;
                if (strpos($code, "/") === false) {
                    continue;
                }
                $query  = "INSERT INTO `$table`(`code`, `oid`, `libelle`) VALUES (?1, ?2, ?3);";
                $query  = $ds->prepare($query, $code, $oid, $libelle);
                $result = $ds->query($query);
                if (!$result) {
                    $msg = $ds->error();
                    $this->addMessage(["Erreur de requéte SQL: $msg", UI_MSG_ERROR]);
                    return false;
                }
                $lineCount++;
            }
        }

        if ($lineCount) {
            $this->addMessage(["Import effectué avec succés de $lineCount lignes", UI_MSG_OK]);
        }
        return true;
    }
}
