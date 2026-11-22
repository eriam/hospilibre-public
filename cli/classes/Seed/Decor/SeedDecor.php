<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed\Decor;

/**
 * UN DÉCOR : tout ce qui fait qu'un jeu de données ressemble à un établissement plutôt qu'à un autre.
 *
 * Le moteur du seed — allocation des lits, calage temporel, invariants, chemins d'écriture — ne varie
 * pas d'un décor à l'autre. Ce qui varie est ici : les unités de soins, les proportions cliniques, les
 * identités, la géographie, le nommage, et au besoin les builders eux-mêmes.
 *
 * DEUX CHOSES QU'UN DÉCOR NE PEUT PAS CONTOURNER, et c'est voulu :
 *
 * 1. LA COHÉRENCE PHYSIQUE. La loi de Little lie les lits, les séjours et la fenêtre : un décor change
 *    les durées de séjour et les bandes d'occupation, mais pas la relation qui les unit. Un profil
 *    physiquement impossible reste impossible à écrire, quel que soit le décor.
 * 2. LE DÉTERMINISME. Les flux de hasard sont nommés et le seed est rejouable au bit près. Un décor
 *    peut tirer autre chose, jamais tirer autrement — pas d'horloge, pas de réseau, pas de
 *    `mt_rand()`. Un test le vérifie par analyse du code.
 */
interface SeedDecor
{
    /** Identifiant court du décor, celui que `--decor` accepte. */
    public function name(): string;

    /** Ce qu'un opérateur doit lire pour savoir à quoi il s'attend. */
    public function description(): string;

    /** Les unités de soins, leurs actes et leurs règles d'admission. */
    public function catalogue(): SpecialityCatalogue;

    /** Les proportions cliniques : durées, bandes d'occupation, mix de séjours, règles du bloc. */
    public function clinicalMix(): ClinicalMix;

    /** D'où viennent les noms et prénoms. */
    public function identities(): IdentitySource;

    /**
     * D'où viennent les communes, ou null quand le décor n'a pas de géographie — les patients ont
     * alors une adresse fictive et sans cohérence territoriale.
     */
    public function geography(): ?GeographySource;

    /**
     * Comment les actes et les diagnostics sont codés, ou null quand le décor ne code pas.
     *
     * Un décor sans codage produit des interventions sans cotation et des séjours sans diagnostic : le
     * jeu de données reste valide — le socle ne l'exige nulle part — mais il est plus pauvre, et le
     * rapport le dit. Rendre null est le comportement juste pour un décor hors de France, dont les
     * actes n'ont pas de code CCAM.
     */
    public function coding(): ?ClinicalCoding;

    /** Comment l'établissement se nomme et s'adresse. */
    public function naming(): EstablishmentNaming;

    /** Quels builders composent le jeu de données. */
    public function builders(): SeedBuilders;
}
