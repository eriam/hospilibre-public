<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

use Ox\Core\Cache;
use Ox\Core\CStoredObject;

/**
 * Comptage et unicité des tags, communs à tous les chemins d'écriture.
 *
 * L'unicité des tags est vérifiée ici, et non laissée à la base : un tag est la promesse
 * qu'une consigne d'exercice peut citer un objet précis (« le lit 12 du service de
 * cardiologie »). Deux objets portant le même tag rendent cette promesse fausse, et personne
 * ne s'en apercevrait — ni contrainte SQL, ni test d'invariant ne couvre ce cas. Autant
 * échouer à l'écriture.
 *
 * LE CACHE D'OBJETS EST VIDÉ PÉRIODIQUEMENT, et c'est indispensable à cette échelle.
 * `CStoredObject::load()` enregistre CHAQUE objet chargé dans un cache de processus
 * (`registerCache()`, couche `Cache::INNER`), et un `store()` en charge plusieurs — l'ancien objet,
 * les références avant, les champs complétés. Rien ne l'expulse jamais. Sur le profil `medium`,
 * soit environ soixante-dix mille écritures, le processus dépassait la limite de 1 Go et mourait sur
 * un « Allowed memory size exhausted » APRÈS avoir écrit les patients — donc en laissant une base à
 * moitié peuplée, ce qui est le pire des états.
 *
 * Vider un cache ne peut pas changer un résultat : c'est la définition d'un cache. Le seul effet est
 * que les objets sont rechargés, et le seed ne relit presque jamais ce qu'il vient d'écrire.
 */
abstract class AbstractSeedWriter implements SeedWriter
{
    /**
     * Écritures entre deux vidages du cache de processus.
     *
     * Assez grand pour que le cache serve à l'intérieur d'un lot — un séjour et ses affectations
     * rechargent le même service, le même lit — et assez petit pour que l'empreinte reste bornée.
     */
    private const CACHE_FLUSH_EVERY = 2000;

    /** @var int Écritures depuis le dernier vidage */
    private $since_flush = 0;

    /** @var array<string, int> classe courte => nombre d'objets écrits */
    private $counts = [];

    /** @var array<string, bool> "classe|tag" déjà attribués */
    private $tags = [];

    /**
     * @throws SeedException
     */
    public function write(CStoredObject $object, ?string $tag = null): CStoredObject
    {
        $class = $object->_class;

        if ($tag !== null) {
            $key = $class . '|' . $tag;

            if (isset($this->tags[$key])) {
                throw new SeedException("Tag déjà attribué : {$class} '{$tag}'");
            }

            $this->tags[$key] = true;
        }

        $this->doWrite($object, $tag);

        $this->counts[$class] = ($this->counts[$class] ?? 0) + 1;

        if (++$this->since_flush >= self::CACHE_FLUSH_EVERY) {
            $this->since_flush = 0;

            Cache::flushInner();
        }

        return $object;
    }

    public function flush(): void
    {
        // Rien à faire par défaut : seul un chemin par lots a quelque chose en attente.
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = $this->counts;
        ksort($counts);

        return $counts;
    }

    public function total(): int
    {
        return array_sum($this->counts);
    }

    /**
     * Écriture effective, propre au chemin.
     *
     * @throws SeedException
     */
    abstract protected function doWrite(CStoredObject $object, ?string $tag): void;
}
