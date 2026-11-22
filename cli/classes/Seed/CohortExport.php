<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

use Hospilibre\Patients\CPatient;
use Hospilibre\System\CFixturesReference;
use Ox\Core\CMbDT;
use Ox\Core\CSQLDataSource;
use Throwable;

/**
 * Écrit en CSV la cohorte de patients qu'un semis a produite.
 *
 * POURQUOI UNE COMMANDE, ET NON UN ACCÈS DIRECT À LA BASE. Un système extérieur qui a besoin de
 * cette liste — un SIL, un PACS, un poste soignant, un jeu d'épreuves — pourrait interroger MariaDB
 * lui-même. Il se lierait alors au SCHÉMA de l'application, qu'il ne contrôle pas et qui bougera.
 * Le seed, lui, sait déjà ce qu'il a créé : sa traçabilité le lui dit. Exporter est une fonction de
 * plus, pas un couplage de plus.
 *
 * ET NON UNE API REST : il n'existe pas d'endpoint de création ni de lecture de patients dans ce
 * fork, et un consommateur qui en supposerait un ne s'en apercevrait qu'en séance.
 *
 * LA SOURCE EST LA TRAÇABILITÉ DU SEED, pas la table des patients. Exporter tous les patients de
 * l'instance ramasserait ceux qu'elle contenait déjà, dont le seed ne sait rien et que personne ne
 * lui a demandés. `fixtures_reference` dit exactement quels patients CE seed a écrits.
 *
 * UNE SEULE REQUÊTE, et non une boucle d'ORM. À vingt-cinq mille patients, charger chaque patient
 * puis ses identifiants externes puis ses séjours ferait trois requêtes par ligne — soixante-quinze
 * mille allers-retours pour un fichier de quelques mégaoctets.
 *
 * LE SÉJOUR RAPPORTÉ EST LE PREMIER QUE LE SEED A CRÉÉ pour ce patient, et ce choix n'est pas
 * arbitraire. Un patient dont l'identité a été imposée par un scénario vit ensuite dans
 * l'établissement comme les autres : il peut obtenir d'autres séjours. Rapporter le plus récent
 * rendrait donc un motif que le scénario n'a pas écrit — « Bilan rénal » pour une patiente dont un
 * autre système montre un scanner thoracique, sous le même IPP. Deux applications qui se
 * contredisent sur un même patient est une FAUSSE PISTE, et une fausse piste coûte plus cher qu'une
 * piste absente.
 *
 * L'ordre retenu est celui de L'ÉCRITURE (`sejour_id`), et non celui des dates d'entrée. Ce n'est
 * pas un raccourci : les ancrés sont servis en priorité au placement, donc le séjour que le scénario
 * leur a prescrit est toujours le premier écrit — tandis que le plus ancien PAR LA DATE peut être un
 * séjour obtenu ensuite, plus tôt dans la fenêtre temporelle. Constaté en test : l'ancré admis en
 * pneumologie pour « Décompensation BPCO » ressortait avec un motif vide, parce qu'un second séjour
 * commençait avant le premier.
 *
 * Les colonnes `service_actuel` et `motif_actuel` disent, elles, où le patient se trouve MAINTENANT,
 * et sont vides s'il est sorti. Elles permettent à un bruit d'être au présent sans contredire le
 * scénario, et ne coûtent qu'une sous-requête corrélée de plus — pas un aller-retour par patient.
 */
final class CohortExport
{
    /** Colonnes écrites, dans cet ordre. */
    public const COLUMNS = [
        'ipp',
        'nom',
        'prenom',
        'naissance',
        'sexe',
        'service',
        'motif_sejour',
        'date_entree',
        'service_actuel',
        'motif_actuel',
        // ⚠ L'EMPREINTE DU JEU D'IDENTITÉS, ET EN DERNIÈRE COLONNE POUR NE DÉPLACER AUCUNE AUTRE :
        // un consommateur qui lit par position continue de fonctionner.
        //
        // POURQUOI DANS LE FICHIER ET NON DANS LE JOURNAL. Un export SE TRANSMET SEUL, et le
        // message de fin du semis reste dans un journal que personne ne joint au CSV. C'est
        // exactement ce qui est arrivé le 2026-10-04 : une cohorte de production conservée comme
        // témoin de référence s'est révélée incomparable à la suivante — mêmes paramètres, mais un
        // déploiement avait changé les tables d'identités entre les deux — et le fichier ne portait
        // rien qui permette de le savoir. Une cohorte antérieure est désormais DÉFINITIVEMENT
        // non étiquetable : ses fichiers d'identités n'ont pas été conservés.
        //
        // RÉPÉTÉE SUR CHAQUE LIGNE plutôt qu'en en-tête de commentaire, et c'est voulu : une ligne
        // de commentaire avant l'en-tête casse les lecteurs CSV stricts, et deux cohortes
        // concaténées perdraient leur provenance. Le surcoût est de neuf octets par ligne.
        'empreinte_identites',
    ];

    /** @var string Classe de traçabilité du seed, clé de la cohorte */
    private $fixtures_class;

    /** @var int Lignes écrites au dernier export */
    private $written = 0;

    /** @var int Patients exportés sans IPP */
    private $without_ipp = 0;

    /** @var int Patients exportés sans séjour */
    private $without_sejour = 0;

    /** @var string Instant de référence des colonnes « actuel », au format 'Y-m-d H:i:s' */
    private $now;

    /**
     * @param string|null $now Instant auquel un séjour est dit EN COURS. Par défaut, maintenant.
     *                         Injectable pour qu'un test n'ait pas à dépendre de l'heure qu'il est.
     */
    public function __construct(string $fixtures_class, ?string $now = null)
    {
        $this->fixtures_class = $fixtures_class;
        $this->now            = $now ?? CMbDT::dateTime();
    }

    /**
     * Écrit le fichier et rend le nombre de lignes.
     *
     * @throws SeedException
     * @throws Throwable
     */
    public function toFile(string $path): int
    {
        $this->assertWritable($path);

        $rows = $this->fetch($this->ippTags());

        if ($rows === []) {
            throw new SeedException(
                "Aucun patient tracé pour '{$this->fixtures_class}' : il n'y a pas de cohorte à"
                . ' exporter. Lancer un semis d\'abord, ou vérifier qu\'il n\'a pas été purgé.'
            );
        }

        $handle = fopen($path, 'w');

        if ($handle === false) {
            throw new SeedException("Impossible d'écrire {$path}");
        }

        try {
            // BOM absent volontairement : le consommateur est un programme, pas un tableur, et un
            // BOM collerait au nom de la première colonne de qui lit le fichier naïvement — la
            // panne exacte que le lecteur d'ancres doit contourner de son côté.
            fputcsv($handle, self::COLUMNS);

            // Calculée UNE FOIS : elle ne dépend pas de la ligne, et la recalculer par patient
            // relirait deux fichiers huit mille fois.
            $fingerprint = FictitiousNames::fingerprint();

            $this->written        = 0;
            $this->without_ipp    = 0;
            $this->without_sejour = 0;

            foreach ($rows as $row) {
                if (($row['ipp'] ?? '') === '' || $row['ipp'] === null) {
                    $this->without_ipp++;
                }

                if (($row['date_entree'] ?? null) === null) {
                    $this->without_sejour++;
                }

                $line = [];
                foreach (self::COLUMNS as $column) {
                    $line[] = $column === 'empreinte_identites'
                        ? $fingerprint
                        : (string)($row[$column] ?? '');
                }

                fputcsv($handle, $line);
                $this->written++;
            }
        } finally {
            fclose($handle);
        }

        return $this->written;
    }

    public function written(): int
    {
        return $this->written;
    }

    /** Patients exportés sans IPP : ligne inutilisable pour un rapprochement. */
    public function withoutIpp(): int
    {
        return $this->without_ipp;
    }

    /** Patients exportés sans séjour : identité seule. */
    public function withoutSejour(): int
    {
        return $this->without_sejour;
    }

    /**
     * Refuse tôt un chemin non inscriptible.
     *
     * Vérifié AVANT la requête : découvrir que le répertoire n'existe pas après avoir lu
     * vingt-cinq mille lignes serait une minute perdue pour rien.
     *
     * @throws SeedException
     */
    private function assertWritable(string $path): void
    {
        $directory = dirname($path);

        if (!is_dir($directory)) {
            throw new SeedException("Répertoire d'export inexistant : {$directory}");
        }

        if (!is_writable($directory) || (is_file($path) && !is_writable($path))) {
            throw new SeedException("Chemin d'export non inscriptible : {$path}");
        }
    }

    /**
     * Les tags d'IPP de TOUS les établissements que ce seed a tracés.
     *
     * Tous, et non celui du dernier. Le tag d'IPP contient l'identifiant de l'établissement, et
     * rien n'oblige un opérateur à purger entre deux semis : plusieurs établissements de seed
     * peuvent coexister, chacun numérotant ses patients avec son propre tag. Ne résoudre qu'un seul
     * établissement rendait un IPP vide pour tous les patients des autres — constaté en vrai sur
     * l'instance de vérification, où trois établissements tracés cohabitaient et où l'export,
     * prenant le PLUS ANCIEN, rendait quatre cents lignes sans IPP alors que les quatre cents
     * identifiants étaient bien en base.
     *
     * Les établissements sont retrouvés par la traçabilité et non par `CGroups::loadCurrent()` :
     * en ligne de commande il n'y a pas de session, donc pas d'établissement courant fiable, et
     * surtout ce n'est pas forcément celui que le seed a écrit.
     *
     * @return string[] tags distincts, `$g` déjà substitué
     * @throws Throwable
     */
    private function ippTags(): array
    {
        $reference = new CFixturesReference();
        $groups    = $reference->loadList(
            [
                'fixtures_class' => $reference->getDS()->prepareLike($this->fixtures_class),
                'object_class'   => "= 'CGroups'",
            ],
            'fixtures_reference_id DESC'
        );

        $tags = [];
        foreach ($groups as $group) {
            $tag = CPatient::getTagIPP($group->object_id);

            if ($tag) {
                $tags[$tag] = true;
            }
        }

        return array_keys($tags);
    }

    /**
     * @param string[] $tags
     *
     * @return array<int, array<string, string|null>>
     * @throws SeedException
     * @throws Throwable
     */
    private function fetch(array $tags): array
    {
        $ds = CSQLDataSource::get('std');

        // Liste construite par `prepare()` valeur par valeur, et non par `prepareIn()` : celui-ci
        // se contente d'entourer ses valeurs de quotes sans les échapper.
        $quoted = [];
        foreach ($tags as $tag) {
            $quoted[] = $ds->prepare('%1', $tag);
        }

        // Aucun établissement tracé, donc aucun tag : la condition ne doit rien apparier, mais la
        // requête doit rester valide — les identités s'exportent même sans IPP, et le compte des
        // lignes sans IPP le dira.
        $tag_condition = $quoted === [] ? "IS NULL AND '0' = '1'" : 'IN (' . implode(', ', $quoted) . ')';

        $query = $ds->prepare(
            <<<'SQL'
                SELECT
                  idex.id400                                        AS ipp,
                  patients.nom                                      AS nom,
                  patients.prenom                                   AS prenom,
                  patients.naissance                                AS naissance,
                  patients.sexe                                     AS sexe,
                  service.nom                                       AS service,
                  premier.libelle                                   AS motif_sejour,
                  COALESCE(premier.entree_reelle, premier.entree_prevue) AS date_entree,
                  unite_actuelle.nom                                AS service_actuel,
                  courant.libelle                                   AS motif_actuel
                FROM `fixtures_reference` AS fr
                INNER JOIN `patients`
                  ON `patients`.`patient_id` = `fr`.`object_id`
                LEFT JOIN `id_sante400` AS idex
                  ON  `idex`.`object_id`    = `patients`.`patient_id`
                  AND `idex`.`object_class` = 'CPatient'
                  AND `idex`.`tag`          /*TAGS*/
                LEFT JOIN `sejour` AS `premier`
                  ON `premier`.`sejour_id` = (
                       SELECT `s2`.`sejour_id`
                       FROM `sejour` AS `s2`
                       WHERE `s2`.`patient_id` = `patients`.`patient_id`
                       ORDER BY `s2`.`sejour_id` ASC
                       LIMIT 1
                     )
                LEFT JOIN `service`
                  ON `service`.`service_id` = `premier`.`service_id`
                LEFT JOIN `sejour` AS `courant`
                  ON `courant`.`sejour_id` = (
                       SELECT `s3`.`sejour_id`
                       FROM `sejour` AS `s3`
                       WHERE `s3`.`patient_id` = `patients`.`patient_id`
                         AND COALESCE(`s3`.`entree_reelle`, `s3`.`entree_prevue`) <= %2
                         AND COALESCE(`s3`.`sortie_reelle`, `s3`.`sortie_prevue`) >= %2
                       ORDER BY COALESCE(`s3`.`entree_reelle`, `s3`.`entree_prevue`) DESC,
                                `s3`.`sejour_id` DESC
                       LIMIT 1
                     )
                LEFT JOIN `service` AS `unite_actuelle`
                  ON `unite_actuelle`.`service_id` = `courant`.`service_id`
                WHERE `fr`.`fixtures_class` = %1
                  AND `fr`.`object_class`   = 'CPatient'
                ORDER BY `patients`.`patient_id` ASC
                SQL,
            $this->fixtures_class,
            $this->now
        );

        // Les tags sont substitués APRÈS `prepare()`, à la place d'un repère inerte : `prepare()`
        // remplace aussi les `%` et `?` NUS par sa première valeur, et un tag qui en contiendrait un
        // serait corrompu s'il était déjà dans la requête au moment de l'appel.
        $query = str_replace('/*TAGS*/', $tag_condition, $query);

        $rows = $ds->loadList($query);

        return is_array($rows) ? $rows : [];
    }
}
