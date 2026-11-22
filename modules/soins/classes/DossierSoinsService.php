<?php

/**
 * @package Hospilibre\Soins
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Réimplémentation GPL indépendante : la classe OXOL d'origine a été
 * retirée en 6a169cc73 mais son appelant DossierSoinsController n'a pas
 * été nettoyé en même temps.
 */

namespace Hospilibre\Soins;

use Exception;
use Hospilibre\Admin\CAccessMedicalData;
use Hospilibre\Admin\CBrisDeGlace;
use Hospilibre\Patients\CPatient;
use Hospilibre\PlanningOp\COperation;
use Hospilibre\PlanningOp\CSejour;
use Hospilibre\System\Forms\CExClassEvent;
use Ox\Core\Autoload\IShortNameAutoloadable;
use Ox\Core\CAppUI;
use Ox\Core\CMbDT;
use Ox\Core\CMbException;
use Ox\Core\CSQLDataSource;
use Ox\Core\Module\CModule;

/**
 * Prépare tout ce dont a besoin la vue "dossier de soins" d'un séjour :
 * résout séjour / patient / opération courante à partir de leurs
 * identifiants, applique les contrôles d'accès (bris de glace,
 * journalisation), puis précharge les références annexes lues par la
 * bannière et les onglets du template inc_dossier_sejour.tpl.
 */
class DossierSoinsService implements IShortNameAutoloadable
{
    /** @var bool */
    public $isPrescriptionInstalled;

    /** @var string */
    public $date;

    /** @var array */
    public $form_tabs = [];

    /** @var CSejour */
    private $sejour;

    /** @var CPatient */
    private $patient;

    /** @var COperation|null */
    private $operation;

    /**
     * @param string      $sejour_id    Identifiant du séjour à afficher
     * @param string      $date         Date pour laquelle le dossier est consulté
     * @param string|null $operation_id Opération courante, si le dossier est ouvert depuis un bloc
     *
     * @throws CMbException Si aucun séjour ne correspond à $sejour_id
     * @throws Exception
     */
    public function __construct(string $sejour_id, string $date, ?string $operation_id = null)
    {
        $sejour = new CSejour();

        if (!$sejour->load($sejour_id)) {
            throw new CMbException("CSejour-not found");
        }

        $this->sejour                  = $sejour;
        $this->date                    = $date;
        $this->isPrescriptionInstalled = (bool) CModule::getActive("dPprescription");

        $patient        = $sejour->loadRefPatient();
        $this->patient  = $patient ?: new CPatient();
        $this->operation = $this->resolveOperation($operation_id);

        $this->preloadViewReferences();
    }

    public function getSejour(): CSejour
    {
        return $this->sejour;
    }

    public function getPatient(): CPatient
    {
        return $this->patient;
    }

    public function getOperation(): ?COperation
    {
        return $this->operation;
    }

    /**
     * Détermine la date à utiliser pour le plan de soins affiché. En
     * temps normal c'est simplement la date demandée ; mais un séjour
     * déjà sorti n'a plus de plan de soins au-delà de sa sortie réelle,
     * donc si la date demandée dépasse celle-ci on retombe sur la date
     * d'entrée pour rester sur une période où le plan existe.
     */
    public function getDatePlanSoins(): string
    {
        $sortieReelle = $this->sejour->sortie_reelle;

        $sejourDejaSorti  = (bool) $sortieReelle;
        $dateApresLaSortie = $sejourDejaSorti && $this->date > CMbDT::date($sortieReelle);

        if ($dateApresLaSortie) {
            return CMbDT::date($this->sejour->entree);
        }

        return $this->date;
    }

    /**
     * Effectue les vérifications et journalisations requises avant de
     * livrer réellement le dossier : trace l'accès à la donnée médicale,
     * résout le NDA du séjour, bloque l'accès si le bris de glace est
     * requis et non accordé au praticien courant, et calcule les alertes
     * "observation" non traitées affichées dans la bannière.
     *
     * @throws Exception
     */
    public function loadDossierSoins(): void
    {
        $sejour = $this->sejour;

        CAccessMedicalData::logAccess($sejour);

        $sejour->loadNDA();

        $accesBloque = CBrisDeGlace::isBrisDeGlaceRequired() && !CAccessMedicalData::checkForSejour($sejour);

        if ($accesBloque) {
            CAppUI::accessDenied();
        }

        $sejour->countAlertsNotHandled("medium", "observation");
    }

    /**
     * Liste les objectifs de soins de ce séjour qui sont encore ouverts
     * mais dont l'échéance (delai) est déjà dépassée à la date du jour.
     * Requête réellement la table objectif_soin via CObjectifSoin : le
     * bandeau "objectifs en retard" du template reflète ce résultat, ce
     * n'est pas une liste vide de principe.
     *
     * @return CObjectifSoin[]
     */
    public function getLateObjectifsSoins(): array
    {
        $ds = CSQLDataSource::get("std");

        $criteres = [
            "sejour_id" => $ds->prepare("= ?", $this->sejour->_id),
            "statut"    => $ds->prepare("= ?", "ouvert"),
            "delai"     => $ds->prepare("< ?", CMbDT::date()),
        ];

        return (new CObjectifSoin())->loadList($criteres);
    }

    /**
     * Charge l'opération $operation_id et ne la retient que si elle
     * existe réellement en base ; sinon le dossier de soins est simplement
     * affiché hors contexte opératoire.
     */
    private function resolveOperation(?string $operation_id): ?COperation
    {
        if (!$operation_id) {
            return null;
        }

        $operation = new COperation();
        $operation->load($operation_id);

        return $operation->_id ? $operation : null;
    }

    /**
     * Précharge, module par module, les références annexes que la
     * bannière et les onglets du dossier de soins vont lire. Chaque bloc
     * dépendant d'un module optionnel ou d'un contexte particulier
     * (opération, maternité) est isolé dans sa propre méthode gardée : la
     * prescription (dPprescription) n'est en particulier chargée nulle
     * part ici, ce module n'étant pas installé sur ce déploiement.
     *
     * @throws Exception
     */
    private function preloadViewReferences(): void
    {
        $this->preloadFormTabs();
        $this->preloadSejourBannerData();
        $this->preloadPatientBannerData();

        if (CModule::getActive("maternite")) {
            $this->preloadMaterniteContext();
        }

        if ($this->operation !== null) {
            $this->preloadOperationContext($this->operation);
        }
    }

    private function preloadFormTabs(): void
    {
        if (!CModule::getActive("forms")) {
            $this->form_tabs = [];

            return;
        }

        $sejour = $this->sejour;

        $this->form_tabs = CExClassEvent::getTabEvents(
            [
                ["tab_dossier_soins_obs_entree", $sejour->loadRefObsEntree()],
                ["tab_dossier_soins", $sejour],
            ]
        );
    }

    private function preloadSejourBannerData(): void
    {
        $sejour = $this->sejour;

        $sejour->loadRefPraticien();
        $sejour->loadJourOp($this->date);
        $sejour->loadRefsOperations();
        $sejour->loadRefCurrAffectation();
        $sejour->loadRefsActesCCAM();
        $sejour->loadRefsActesNGAP();
        $sejour->loadPatientBanner();
        $sejour->loadRefsRDVExternes();
    }

    private function preloadPatientBannerData(): void
    {
        $patient = $this->patient;

        $patient->countINS();
        $patient->loadRefsNotes();
        $patient->_homonyme = count($patient->getPhoning($this->date));
    }

    private function preloadMaterniteContext(): void
    {
        $sejour  = $this->sejour;
        $patient = $this->patient;

        $grossesse = $sejour->loadRefGrossesse();
        if ($grossesse && $grossesse->_id) {
            $grossesse->loadRefDossierPerinat();
        }

        $naissance = $sejour->loadRefNaissance();
        if ($naissance && $naissance->_id) {
            $naissance->loadRefGrossesse();

            $sejourMaman   = $naissance->loadRefSejourMaman();
            $estUnNouveauNe = $patient->civilite === "enf" && $sejourMaman && $sejourMaman->_id;

            if ($estUnNouveauNe) {
                $maman = $sejourMaman->loadRefPatient();
                if ($maman && $maman->_id) {
                    $maman->loadLastGrossesse();
                }
            }
        }

        $patient->loadLastGrossesse();
    }

    private function preloadOperationContext(COperation $operation): void
    {
        $operation->loadRefPlageOp();

        $anesth = $operation->loadRefAnesth();

        if ($anesth && $anesth->_id) {
            $anesth->loadRefFunction();
        }
    }
}
