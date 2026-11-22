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
 * Chemin d'écriture du seed.
 *
 * Deux implémentations coexistent derrière cette interface parce que les contraintes ne sont
 * pas les mêmes selon le volume : le référentiel (établissement, services, chambres, lits,
 * bloc, salles, plages) est peu volumineux et fortement contraint, donc il passe par l'ORM et
 * bénéficie de `check()`, des déclencheurs et des journaux. Les entités de masse (patients,
 * séjours, affectations, opérations) ne peuvent pas payer 5 à 20 ms par objet quand on en
 * attend 100 000 : au-delà d'un certain profil elles passent par une écriture par lots.
 *
 * Les builders ne savent pas lequel des deux ils utilisent — c'est tout l'intérêt.
 */
interface SeedWriter
{
    /**
     * Écrit un objet, éventuellement étiqueté d'un tag stable.
     *
     * Un tag n'a d'intérêt que pour ce qu'une consigne d'exercice peut citer : établissement,
     * service, chambre, lit, salle, praticien. Les objets de masse n'en reçoivent pas.
     *
     * @return CStoredObject l'objet écrit, pour enchaîner
     * @throws SeedException si l'écriture échoue ou si le tag est déjà pris
     */
    public function write(CStoredObject $object, ?string $tag = null): CStoredObject;

    /**
     * Force l'envoi de ce qui serait encore en attente.
     *
     * Sans effet sur le chemin ORM, qui écrit au fil de l'eau ; indispensable sur un chemin
     * par lots. Les builders l'appellent en fin d'étape sans savoir lequel ils pilotent.
     *
     * @throws SeedException
     */
    public function flush(): void;

    /**
     * @return array<string, int> classe courte => nombre d'objets écrits
     */
    public function counts(): array;

    public function total(): int;

    /**
     * Nom du chemin d'écriture, pour la sortie de la commande.
     */
    public function name(): string;
}
