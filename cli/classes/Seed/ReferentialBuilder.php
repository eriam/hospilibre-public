<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

use Hospilibre\Hospi\CAffectationUserService;

/**
 * Orchestre la construction du référentiel, dans l'ordre imposé par les dépendances.
 *
 * L'ordre est du CODE LISIBLE ici, et non un effet de bord du tri alphabétique du chargeur de
 * fixtures : `FixturesLoader::orderFixtures()` trie les groupes par nom et l'ordre intra-groupe
 * par priorité décroissante, avec un index qui fuit d'une fixture à la suivante quand la
 * priorité est omise. Inexploitable pour une chaîne de dépendances à plusieurs étages.
 */
final class ReferentialBuilder
{
    /** @var SeedContext */
    private $context;

    public function __construct(SeedContext $context)
    {
        $this->context = $context;
    }

    /**
     * @throws SeedException
     */
    /**
     * Inscrit chaque soignant dans son service, de façon DURABLE.
     *
     * UNE LIGNE SANS DATE, et ce n'est pas un détournement : `CAffectationUserService` porte les
     * deux sémantiques par conception, et son propre accesseur le prouve —
     * `listUsersService($service_id)` filtre sur « date IS NULL » pour rendre LES AGENTS DU
     * SERVICE, tandis que `loadResponsableJour($service_id, $date)` interroge une date précise
     * pour le responsable du jour. Une ligne non datée est donc l'appartenance, une ligne datée le
     * tour de garde. J'avais d'abord craint une surcharge de sémantique ; la lecture du code la
     * démentit.
     *
     * POURQUOI C'EST NÉCESSAIRE. Le périmètre par unité a besoin de « mes unités », et rien ne
     * permettait de les déduire : `CService` ne déclare aucune fonction, `CFunctions` aucun
     * service, et les rapprocher par le libellé est faux par construction — le décor nomme l'unité
     * « Urgences » et sa fonction « Médecine d'urgence », « UHCD » et « Hospitalisation de courte
     * durée ». Un rapprochement par nom aurait marché sur les unités dont les deux noms coïncident
     * et échoué EN SILENCE sur les autres.
     */
    private function linkCaregiversToServices(Referential $referential): void
    {
        foreach ($referential->serviceSlugs() as $slug) {
            $service = $referential->service($slug);

            // LES PRATICIENS AUSSI, et c'est indispensable : un médecin de médecine interne
            // appartient à son unité autant que l'infirmière qui y travaille. Les omettre
            // donnerait aux praticiens un périmètre VIDE, donc aucun accès à leurs propres
            // patients dès que le filtre sera posé — l'inverse de ce qu'on cherche.
            // Les anesthésistes ne tiennent pas de service : ils ne sont pas dans cette liste, et
            // leur périmètre devra se raisonner autrement (par le bloc, pas par l'unité).
            $members = array_merge($referential->practitioners($slug), $referential->caregivers($slug));

            foreach ($members as $caregiver) {
                $link             = new CAffectationUserService();
                $link->user_id    = $caregiver->_id;
                $link->service_id = $service->_id;
                // `date` laissée NULLE : voir l'en-tête de méthode.

                $this->context->write($link);
            }
        }
    }

    public function build(): Referential
    {
        // Avant toute écriture : un module inactif ne produit pas d'erreur, il produit un
        // graphe troué. Mieux vaut refuser de commencer.
        ModuleGuard::assertActive();

        // Le dimensionnement du bloc est calculé ICI, une seule fois, et passé aux deux builders
        // qui en dépendent : l'effectif d'anesthésie se déduit des salles, et les salles de
        // l'activité chirurgicale attendue. Le calculer deux fois laisserait les deux
        // dimensionnements diverger au premier changement de règle.
        $profile = $this->context->profile();
        $counts  = $this->context->decor()->catalogue()->counts($profile->services());
        $theatre = $profile->operatingTheatre($counts['surgical'], $counts['ward']);

        $etablissement = new EtablissementBuilder($this->context);
        $referential   = $etablissement->build($theatre['rooms']);

        (new ServiceBuilder($this->context))->build($referential);

        // LE RATTACHEMENT DURABLE DU PERSONNEL À SON UNITÉ, et il doit venir ICI : le personnel est
        // créé par EtablissementBuilder, les services par ServiceBuilder, et le lien exige les
        // deux. C'est le préalable du périmètre par unité d'exercice — sans lui il n'y a rien à
        // comparer, `CService` et `CFunctions` n'étant liés par aucune colonne.
        $this->linkCaregiversToServices($referential);

        (new BlocBuilder($this->context))->build($referential, $theatre);

        $this->context->writer()->flush();

        return $referential;
    }
}
