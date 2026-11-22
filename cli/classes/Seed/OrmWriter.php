<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

use Exception;
use Hospilibre\System\CFixturesReference;
use Ox\Core\CStoredObject;

/**
 * Chemin d'écriture par l'ORM : CStoredObject::store().
 *
 * C'est le chemin par défaut, et le seul utilisé pour le référentiel. Il respecte
 * intégralement `check()`, les déclencheurs et les journaux — c'est-à-dire les règles métier,
 * là où elles comptent le plus.
 *
 * Chaque objet écrit est tracé dans CFixturesReference, ce qui rend le jeu de données
 * purgeable par `ox-fixtures:load --only-purge` : la traçabilité suit LE CHEMIN D'ÉCRITURE et
 * non la classe d'objet, sans quoi les deux modes divergeraient.
 */
final class OrmWriter extends AbstractSeedWriter
{
    /** @var string Classe déclarée comme émettrice des références, base de la purge ciblée */
    private $fixtures_class;

    /** @var bool */
    private $trace;

    /**
     * @param string $fixtures_class classe portée par les lignes CFixturesReference produites.
     *                               C'est la clé de la purge ciblée : deux runs qui la
     *                               partagent se purgent l'un l'autre.
     * @param bool   $trace          false pour ne rien tracer. À n'utiliser que si la purge est
     *                               assurée autrement (recréation de la base, plages
     *                               d'identifiants), sinon le jeu de données devient orphelin.
     */
    public function __construct(string $fixtures_class, bool $trace = true)
    {
        $this->fixtures_class = $fixtures_class;
        $this->trace          = $trace;
    }

    public function name(): string
    {
        return SeedProfile::WRITER_ORM;
    }

    /**
     * @throws SeedException
     */
    protected function doWrite(CStoredObject $object, ?string $tag): void
    {
        try {
            $msg = $object->store();
        } catch (Exception $e) {
            throw new SeedException(
                "Écriture de {$object->_class} impossible : " . $e->getMessage(),
                0,
                $e
            );
        }

        if ($msg) {
            throw new SeedException("Écriture de {$object->_class} refusée : {$msg}");
        }

        if (!$this->trace) {
            return;
        }

        $reference                 = new CFixturesReference();
        $reference->fixtures_class = $this->fixtures_class;
        $reference->object_class   = $object->_class;
        $reference->object_id      = $object->_id;
        $reference->tag            = $tag;

        try {
            $msg = $reference->store();
        } catch (Exception $e) {
            throw new SeedException(
                "Traçage de {$object->_guid} impossible : " . $e->getMessage(),
                0,
                $e
            );
        }

        if ($msg) {
            throw new SeedException("Traçage de {$object->_guid} refusé : {$msg}");
        }
    }
}
