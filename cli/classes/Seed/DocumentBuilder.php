<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

use Hospilibre\Files\CFile;
use Hospilibre\PlanningOp\COperation;
use Hospilibre\PlanningOp\CSejour;
use Ox\Core\CStoredObject;

/**
 * Les pièces jointes du dossier : courrier du médecin traitant, consentement, imagerie, anapath.
 *
 * CE QUE C'EST, ET CE QUE ÇA N'EST PAS. Un dossier d'hospitalisation porte deux natures de
 * documents, et l'application les range à deux endroits distincts :
 *
 *   — LES DOCUMENTS PRODUITS PAR L'ÉTABLISSEMENT sont des `CCompteRendu` : du texte en base, rédigé
 *     dans l'application, modifiable et réimprimable. Le compte rendu opératoire en est un.
 *   — LES DOCUMENTS REÇUS DE L'EXTÉRIEUR, ou signés sur papier, sont des `CFile` : un vrai fichier
 *     sur le disque, qu'on ne retape pas. Le courrier du médecin traitant, le consentement signé,
 *     le compte rendu d'imagerie du cabinet de ville en sont.
 *
 * Le semis ne produisait QUE la première nature. L'onglet des documents d'un dossier n'affichait
 * donc aucune pièce jointe, et le module `dPfiles` — qui est l'un de ceux qu'on montre — restait
 * entièrement vide sur un jeu de plusieurs milliers de séjours.
 *
 * LE CONTENU EST DU HTML, ET NON UN PDF. Ma première version produisait bien des PDF. La raison du
 * changement est écrite au long dans `SeedAttachment`, et elle n'est PAS celle du volume : elle est
 * qu'aucun lecteur de PDF indépendant n'est installé sur la machine de développement, donc qu'il
 * n'existe aucun instrument pour dire si un PDF produit ici s'ouvre réellement. Le volume était mon
 * premier argument, et je l'avais appuyé sur un chiffre faux — voir là-bas.
 *
 * ⚠ ET N'ATTENDEZ AUCUN FILET DE L'APPLICATION SUR LA VALIDITÉ DU CONTENU. J'avais écrit ici qu'« un
 * PDF mal formé serait refusé par l'application elle-même ». C'est FAUX, et un test l'a montré :
 * `CFile::isAllowedFileContent()` vérifie SÉPARÉMENT que l'extension est dans sa liste blanche et
 * que le type MIME relu du contenu est dans la sienne, SANS vérifier qu'ils correspondent. Comme
 * `text/plain` et `application/octet-stream` y figurent tous deux, n'importe quoi passe. La
 * cohérence entre ce qu'on annonce et ce qu'on écrit est donc à notre charge seule — voir
 * `SeedAttachmentTest`, qui consigne cette limite.
 *
 * ⚠ CE NE SONT PAS DES SCANS, ET IL FAUT LE DIRE PLUTÔT QUE LE MASQUER. Un vrai courrier de médecin
 * traitant est une image scannée, souvent de travers, parfois manuscrite. On ne fabrique pas un scan
 * plausible sans image source. Le lecteur d'un jeu de données doit savoir ce qu'il regarde.
 *
 * LA SÉPARATION FAITS / PROSE EST LA MÊME QUE PARTOUT : l'en-tête du document porte les valeurs lues
 * dans le dossier, le corps vient du corpus, qui interdit les chiffres. Un courrier qui annoncerait
 * une date différente de celle du séjour contredirait le dossier auquel il est attaché.
 */
final class DocumentBuilder
{
    /**
     * Part, en pour mille, des séjours recevant un courrier du médecin traitant.
     *
     * PAS TOUS, ET C'EST LE POINT : une admission non programmée n'a pas de courrier d'adressage, et
     * un patient qui vient de lui-même non plus. Le chiffre est un choix de vraisemblance.
     */
    private const COURRIER_PER_MILLE = 550;

    /** Part, en pour mille, des séjours portant un compte rendu d'imagerie de ville. */
    private const IMAGERIE_PER_MILLE = 300;

    /**
     * Part, en pour mille, des interventions suivies d'un examen anatomopathologique.
     *
     * Toute intervention n'envoie pas de pièce au laboratoire : une cure de hernie n'en envoie pas,
     * une exérèse si.
     */
    private const ANAPATH_PER_MILLE = 400;

    /**
     * ⚠ PAS DE PLAFOND PAR SÉJOUR, ET C'EST UN CHOIX RÉFLÉCHI. Une première version en posait un à
     * quatre documents. Mais un séjour à trois interventions doit porter TROIS consentements : le
     * recueil est obligatoire avant chaque acte, et en supprimer un parce qu'un compteur est atteint
     * produirait un dossier en faute — pas un dossier allégé. Le volume est borné par les
     * probabilités ci-dessus et par le nombre réel d'interventions, qui est lui-même borné.
     */

    /** @var SeedContext */
    private $context;

    /** @var array<string, int> */
    private $counters = [
        'fichiers'      => 0,
        'courriers'     => 0,
        'consentements' => 0,
        'imageries'     => 0,
        'anapaths'      => 0,
        'refuses'       => 0,
    ];

    public function __construct(SeedContext $context)
    {
        $this->context = $context;
    }

    /**
     * @return array<string, int>
     */
    public function counters(): array
    {
        return $this->counters;
    }

    /**
     * Les pièces jointes du SÉJOUR : courrier d'adressage et imagerie de ville.
     *
     * ⚠ APPELÉ APRÈS L'ÉCRITURE DU SÉJOUR : `CFile::object_id` exige son identifiant. C'est la
     * troisième fois que cette contrainte se présente dans le semis — après le dossier d'anesthésie
     * et le compte rendu de consultation — et elle s'était déjà payée une fois en laissant 336
     * `operation_id` nuls.
     *
     * @throws SeedException
     */
    public function buildForStay(CSejour $sejour, bool $emergency): void
    {
        if (!$sejour->_id || !ReportCorpus::isAvailable()) {
            return;
        }

        $random = $this->context->stream('documents');
        $facts  = $this->stayFacts($sejour);

        // LE COURRIER D'ADRESSAGE N'EXISTE PAS EN URGENCE, par définition : un patient amené aux
        // urgences n'a pas été adressé par son médecin pour cette admission. Lui en fabriquer un
        // antidaterait un courrier qui n'a pas été écrit.
        if (!$emergency && $random->chanceInThousand(self::COURRIER_PER_MILLE)) {
            if ($this->writeFor($sejour, 'Courrier du médecin traitant', 'documents_courriers', $facts, $random)) {
                $this->counters['courriers']++;
            }
        }

        if ($random->chanceInThousand(self::IMAGERIE_PER_MILLE)) {
            if ($this->writeFor($sejour, "Compte rendu d'imagerie", 'documents_imagerie', $facts, $random)) {
                $this->counters['imageries']++;
            }
        }
    }

    /**
     * Les pièces jointes d'une INTERVENTION : consentement signé et compte rendu d'anapath.
     *
     * ⚠ APPELÉ APRÈS L'ÉCRITURE DE L'INTERVENTION, pour la même raison.
     *
     * @throws SeedException
     */
    public function buildForOperation(COperation $operation): void
    {
        if (!$operation->_id || !ReportCorpus::isAvailable()) {
            return;
        }

        $random = $this->context->stream('documents');
        $facts  = $this->operationFacts($operation);

        // LE CONSENTEMENT EST SYSTÉMATIQUE, et ce n'est pas un choix de vraisemblance : le recueil
        // du consentement éclairé est une obligation avant tout acte. Un dossier chirurgical sans
        // consentement serait un dossier en faute, pas un dossier incomplet.
        if ($this->writeFor($operation, 'Consentement éclairé signé', 'documents_consentements', $facts, $random)) {
            $this->counters['consentements']++;
        }

        if ($random->chanceInThousand(self::ANAPATH_PER_MILLE)) {
            if ($this->writeFor($operation, "Compte rendu d'anatomopathologie", 'documents_anapath', $facts, $random)) {
                $this->counters['anapaths']++;
            }
        }
    }

    /**
     * Écrit un document sur l'objet porteur, et dit si ça a réussi.
     *
     * @param array<string, string> $facts
     *
     * @throws SeedException
     */
    private function writeFor(
        CStoredObject $owner,
        string $title,
        string $section,
        array $facts,
        SeedRandom $random
    ): bool {
        $body = (string)$random->pick(ReportCorpus::section($section));

        $file               = new CFile();
        $file->object_class = $owner->_class;
        $file->object_id    = $owner->_id;
        $file->file_name    = $this->fileName($title);
        $file->file_type    = SeedAttachment::MIME;

        // ⚠ `rotation` À ZÉRO EXPLICITEMENT, ET CE N'EST PAS COSMÉTIQUE. Quand elle est nulle,
        // `CFile::store()` appelle `loadNbPages()`, qui lance GHOSTSCRIPT PAR `exec()` pour compter
        // les pages — un processus externe par document, et une dépendance à un binaire qui peut
        // ne pas être installé. Sur un semis qui produit des milliers de pièces, c'est des milliers
        // de processus pour une information dont nous n'avons aucun besoin.
        $file->rotation = 0;

        // ⚠ `fillFields()` EXPLICITEMENT, ET AVANT `store()`. C'est contre-intuitif — on attend d'un
        // `store()` qu'il remplisse ce qu'il peut — mais l'ordre interne de `CFile::store()` l'exige :
        // il ÉCRIT LE FICHIER SUR LE DISQUE avant d'appeler `parent::store()`, et c'est ce dernier
        // qui déclenche `fillFields()`. Le nom réel sur le disque est donc encore vide au moment où
        // il faut l'utiliser, et `prepareDir()` refuse avec « CFile-error-Empty
        // file_real_filename ». Le premier semis des pièces jointes s'est arrêté exactement là.
        //
        // CE N'EST PAS UN CONTOURNEMENT : l'application fait de même en cinq endroits —
        // `ajax_pdf.php`, `do_pdf_cfile_aed.php`, `CCompteRendu`, `CWkhtmlToPDF`,
        // `print_planning_all_sejour.php`. C'est la convention d'écriture d'un CFile par programme.
        $file->fillFields();
        $file->setContent(SeedAttachment::render($title, $facts, $body));

        $written = $this->context->write($file);

        if (!$written->_id) {
            // COMPTÉ, PAS TU. `CFile::store()` rend une CHAÎNE D'ERREUR plutôt que de lever quand le
            // répertoire n'est pas inscriptible ou que le type est refusé : sans ce compteur, un
            // volume mal monté donnerait un semis « réussi » et un onglet de documents vide, ce qui
            // est exactement le défaut que ce bâtisseur existe pour combler.
            $this->counters['refuses']++;

            return false;
        }

        $this->counters['fichiers']++;

        return true;
    }

    /**
     * Le nom affiché dans le dossier, avec son extension.
     *
     * ⚠ L'EXTENSION EST OBLIGATOIRE ET CONTRÔLÉE : `CFile::store()` refuse le fichier dont
     * l'extension n'est pas dans sa liste blanche. Un titre sans extension serait rejeté, et le
     * refus ne se verrait que dans le compteur — pas à l'écran.
     *
     * LES ACCENTS SONT RETIRÉS DU NOM DE FICHIER, pas du contenu : `CFile::store()` normalise
     * lui-même le nom pour éviter la traversée de répertoire, et un nom déjà propre évite de
     * dépendre de sa normalisation.
     */
    private function fileName(string $title): string
    {
        $slug = strtr($title, [' ' => '-', "'" => '-', 'é' => 'e', 'è' => 'e', 'ê' => 'e']);

        return $slug . '.' . SeedAttachment::EXTENSION;
    }

    /**
     * @return array<string, string>
     */
    private function stayFacts(CSejour $sejour): array
    {
        $facts = [];

        if ($sejour->entree_prevue) {
            $facts['Admission prévue'] = (string)$sejour->entree_prevue;
        }

        if ($sejour->libelle) {
            $facts['Motif'] = (string)$sejour->libelle;
        }

        return $facts;
    }

    /**
     * @return array<string, string>
     */
    private function operationFacts(COperation $operation): array
    {
        $facts = [];

        if ($operation->date) {
            $facts['Date de l\'intervention'] = (string)$operation->date;
        }

        if ($operation->libelle) {
            $facts['Intervention'] = (string)$operation->libelle;
        }

        if ($operation->codes_ccam) {
            $facts['Acte CCAM'] = (string)$operation->codes_ccam;
        }

        return $facts;
    }
}
