<?php
/**
 * @package Hospilibre\Core
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Core;

use PDO;

/**
 * Class CPDOODBCDataSource
 */
class CPDOODBCDataSource extends CPDODataSource {
  protected $driver_name = "odbc";

  /**
   * Connection
   *
   * @param string $host
   * @param string $name
   * @param string $user
   * @param string $pass
   * @param array  $connection_options
   *
   * @return PDO|resource
   */
  function connect($host, $name, $user, $pass, $connection_options = []) {
    if (!class_exists(PDO::class)) {
      // Voir CSQLDataSource::connectionFailed().
      return $this->connectionFailed("FATAL ERROR: PDO support not available. Please check your configuration.");
    }

    if (!$name) {
      $dsn = "$this->driver_name:$host";
    }
    else {
      $dsn = "$this->driver_name:$host;Database=$name;";
    }

    $link = new PDO($dsn, $user, $pass);
    $link->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);

    return $this->link = $link;
  }
}
