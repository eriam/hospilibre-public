<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

use Ox\Core\CStoredObject;

/**
 * Chemin d'écriture qui n'écrit rien : il retient ce qu'on lui donne.
 *
 * Deux usages, et c'est pour le premier qu'il vit dans classes/ et non dans les tests :
 *
 * 1. `ox-seed:hospi --dry-run` — calculer et rapporter l'échelle réelle du jeu de données sans
 *    toucher la base. Un dry-run qui ne ferait qu'estimer à partir du profil ne vérifierait
 *    rien ; ici ce sont les builders qui tournent pour de vrai, seule l'écriture est retenue.
 * 2. Tester les builders sans base, en inspectant les objets construits.
 *
 * Les objets reçoivent un identifiant SYNTHÉTIQUE, croissant, attribué ici. Sans lui, le dry-run
 * s'arrêtait au premier étage : tous les objets auraient un `_id` nul, donc les enfants ne
 * pourraient pas être rattachés, et les allocateurs — lits, plages, patients — confondraient tous
 * leurs sujets en une seule clé vide. Avec lui, le graphe complet se construit en mémoire et les
 * comptes rapportés sont ceux d'un vrai run.
 *
 * Ce qu'un dry-run ne vérifie PAS, et c'est important : aucun `store()` n'a lieu, donc aucune règle
 * métier du socle n'est appliquée. Un dry-run confirme l'échelle et la cohérence interne du
 * générateur, pas que la base acceptera le résultat.
 */
final class CollectingWriter extends AbstractSeedWriter
{
    /** @var array<int, array{object: CStoredObject, tag: string|null}> */
    private $written = [];

    /** @var bool */
    private $keep_objects;

    /** @var int Dernier identifiant synthétique attribué */
    private $last_id = 0;

    /**
     * @param bool $keep_objects false pour ne compter que les objets sans les retenir. Sur un
     *                           profil `xl` (~100 000 objets), tout garder en mémoire n'a pas
     *                           de sens : le dry-run ne veut que les comptes.
     */
    public function __construct(bool $keep_objects = true)
    {
        $this->keep_objects = $keep_objects;
    }

    public function name(): string
    {
        return 'collect';
    }

    /**
     * @return array<int, array{object: CStoredObject, tag: string|null}>
     */
    public function written(): array
    {
        return $this->written;
    }

    /**
     * Objets retenus d'une classe donnée (nom court, ex. 'CLit').
     *
     * @return CStoredObject[]
     */
    public function of(string $class): array
    {
        $objects = [];
        foreach ($this->written as $entry) {
            if ($entry['object']->_class === $class) {
                $objects[] = $entry['object'];
            }
        }

        return $objects;
    }

    /**
     * Objet portant un tag donné, ou null.
     */
    public function tagged(string $tag): ?CStoredObject
    {
        foreach ($this->written as $entry) {
            if ($entry['tag'] === $tag) {
                return $entry['object'];
            }
        }

        return null;
    }

    protected function doWrite(CStoredObject $object, ?string $tag): void
    {
        // Identifiant synthétique : l'affectation à `_id` renseigne aussi la clé primaire, celle-ci
        // étant liée par référence dans CModelObject::initialize().
        if (!$object->_id) {
            $object->_id = ++$this->last_id;
        }

        if ($this->keep_objects) {
            $this->written[] = ['object' => $object, 'tag' => $tag];
        }
    }
}
