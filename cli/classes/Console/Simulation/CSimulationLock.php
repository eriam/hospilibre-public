<?php

/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Cli\Console\Simulation;

/** Verrou exclusif NON bloquant (flock) : une seule écriture de simulation à la fois par instance
 *  (rattrapage du boot, tick du conteneur, tick externe éventuel, purge). */
class CSimulationLock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private string $path) {}

    /** Verrou de l'instance : files/sim.lock (files/ est un volume, écrivable par www-data). */
    public static function forRoot(string $root): self
    {
        return new self(rtrim($root, '/') . '/files/sim.lock');
    }

    public function acquire(): bool
    {
        $handle = @fopen($this->path, 'c');
        if ($handle === false) {
            throw new \RuntimeException("Verrou de simulation impossible à ouvrir : {$this->path}");
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        $this->handle = $handle;

        return true;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }
}
