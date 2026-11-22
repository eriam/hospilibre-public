<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Console;

use Exception;
use Ox\Cli\HospilibreCommand;
use Ox\Core\CApp;
use Ox\Core\CSQLDataSource;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * D'où viennent RÉELLEMENT les patronymes de la cohorte en base ?
 *
 * POURQUOI CETTE COMMANDE EXISTE, et c'est une leçon payée trois fois. La question « la cohorte
 * porte-t-elle encore les identités anglophones ? » s'est trompée de mesure à chaque tentative :
 *
 *   1. le rapport du semis annonçait « fichier cli/resources/seed-identites.csv » alors qu'il
 *      lisait les personas INSEE — grandeur juste (3 000 noms), étiquette fausse ;
 *   2. une requête énumérant SEPT patronymes connus rendait « anglophones : 3 » sur 8 324 patients,
 *      soit 0,04 %, ce qui se lit « rien à faire ». Le 3 était exact : c'est le nombre de patients
 *      portant l'un de ces sept noms-là. Le mot « anglophones » en en-tête, lui, promettait une
 *      mesure que le prédicat ne faisait pas. L'échantillon a montré une cohorte anglophone DE BOUT
 *      EN BOUT — 24 noms tirés à deux endroits, aucun français.
 *
 * LE SEUL PRÉDICAT QUI RÉPONDE À LA QUESTION est donc l'inverse d'une liste noire : « combien de
 * patients portent un nom PRÉSENT DANS LA SOURCE ACTUELLE ». Une liste blanche ne peut pas
 * sous-estimer, puisqu'elle n'a pas à énumérer ce qu'elle cherche à exclure — et l'ensemble de
 * référence est le fichier que le semis lit vraiment, pas une idée de ce qu'il devrait contenir.
 *
 * ⚠ ELLE N'ÉCRIT RIEN et ne modifie aucune identité : elle compare et rapporte.
 *
 * CE QU'ELLE NE PROUVE PAS. Un nom absent de la source n'est pas forcément anglophone : il peut
 * venir d'une saisie manuelle, d'un import, ou d'une autre version du fichier. La commande dit
 * « hors source », jamais « anglophone » — c'est exactement la distinction que les deux mesures
 * précédentes avaient perdue.
 */
class IdentitiesAudit extends HospilibreCommand
{
    /** En deçà de cette part de patients dont le nom vient de la source, on considère l'écart établi. */
    private const EXPECTED_SHARE_PER_CENT = 90;

    /** @var SymfonyStyle */
    private $io;

    /** @var string */
    private $root;

    protected function configure(): void
    {
        $this
            ->setName('ox-seed:identities')
            ->setDescription('Compare les patronymes de la cohorte à la source d\'identités du dépôt')
            ->addOption(
                'path',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Racine de la copie de travail',
                dirname(__DIR__, 3)
            )
            ->addOption(
                'sample',
                null,
                InputOption::VALUE_OPTIONAL,
                'Nombre de noms hors source à montrer',
                '15'
            );
    }

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io   = new SymfonyStyle($input, $output);
        $this->root = rtrim((string)$input->getOption('path'), '/');
        $sample     = max(0, (int)$input->getOption('sample'));

        $this->bootstrap();

        $this->io->title('Origine des patronymes de la cohorte');

        $source_file = $this->root . '/modules/populate/resources/personas/noms.csv';

        if (!is_file($source_file)) {
            $this->io->error("Source d'identités introuvable : {$source_file}");

            return self::FAILURE;
        }

        $source = $this->foldedSource($source_file);

        if ($source === []) {
            $this->io->error("Source d'identités vide : {$source_file}");

            return self::FAILURE;
        }

        $ds = CSQLDataSource::get('std');

        if (!$ds) {
            $this->io->error("La datasource « std » n'est pas joignable.");

            return self::FAILURE;
        }

        // GROUPÉ PAR NOM, et non une ligne par patient : la cohorte de production compte 8 324
        // patients pour 5 429 patronymes distincts, et c'est le nom qu'on compare. Le compte reste
        // porté par la ligne pour que la part se calcule sur les PATIENTS — un décompte de noms
        // distincts donnerait une autre réponse, et pas celle qu'on cherche.
        $rows = $ds->loadList('SELECT COUNT(*) n, nom FROM patients GROUP BY nom');

        if (!is_array($rows) || $rows === []) {
            $this->io->warning('Aucun patient en base : rien à comparer.');

            return self::SUCCESS;
        }

        $patients = 0;
        $in       = 0;
        $names    = 0;
        $names_in = 0;
        $outside  = [];

        foreach ($rows as $_row) {
            $count = (int)$_row['n'];
            $name  = self::fold((string)$_row['nom']);

            $patients += $count;
            $names++;

            if ($name !== '' && isset($source[$name])) {
                $in       += $count;
                $names_in++;
                continue;
            }

            $outside[(string)$_row['nom']] = $count;
        }

        $share = $patients > 0 ? (100 * $in / $patients) : 0.0;

        $this->io->definitionList(
            ['source' => 'modules/populate/resources/personas/noms.csv (' . count($source) . ' patronymes)'],
            ['patients' => (string)$patients],
            ['patronymes distincts' => (string)$names],
            ['patients dont le nom vient de la source' => sprintf('%d (%.1f %%)', $in, $share)],
            ['patronymes venant de la source' => sprintf('%d / %d', $names_in, $names)]
        );

        if ($outside !== [] && $sample > 0) {
            arsort($outside);

            $this->io->section('Noms HORS SOURCE, les plus portés');
            $this->io->listing(
                array_map(
                    static fn(string $name): string => $name . ' (' . $outside[$name] . ')',
                    array_slice(array_keys($outside), 0, $sample)
                )
            );
        }

        if ($share < self::EXPECTED_SHARE_PER_CENT) {
            $this->io->error(
                sprintf(
                    "ÉCART ÉTABLI : %.1f %% des patients portent un nom de la source, moins que les "
                    . "%d %% attendus. La cohorte a été produite par une AUTRE source d'identités — "
                    . "ce qui ne dit pas laquelle. Un resemis (ox-seed:hospi --purge) la réécrirait "
                    . "depuis celle du dépôt.",
                    $share,
                    self::EXPECTED_SHARE_PER_CENT
                )
            );

            return self::FAILURE;
        }

        $this->io->success(
            sprintf('%.1f %% des patients portent un nom de la source du dépôt.', $share)
        );

        return self::SUCCESS;
    }

    /**
     * Les patronymes de la source, repliés, en clés pour une recherche en temps constant.
     *
     * @return array<string, true>
     */
    private function foldedSource(string $file): array
    {
        $source = [];

        foreach ((array)file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim((string)$line);

            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }

            $folded = self::fold($line);

            if ($folded !== '') {
                $source[$folded] = true;
            }
        }

        return $source;
    }

    /**
     * Replie un nom pour que la comparaison porte sur le nom et non sur sa typographie.
     *
     * CE QUE LE REPLI DOIT ABSORBER, et chacun de ces points ferait rater des noms justes :
     * la CASSE (la base stocke « LE GUEN », le fichier « Le Guen »), les ACCENTS (« Pérez » contre
     * « PEREZ », la base les perdant souvent à la saisie), et les SÉPARATEURS (espaces, traits
     * d'union, apostrophes : « LE-GUEN », « D'ANGELO »). Sans cela, l'audit conclurait à un écart
     * sur une cohorte parfaitement conforme — l'erreur symétrique de celle qu'il corrige.
     */
    private static function fold(string $value): string
    {
        $folded = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        $folded = strtoupper((string)($folded === false ? $value : $folded));

        return (string)preg_replace('/[^A-Z0-9]/', '', $folded);
    }

    /**
     * @throws Exception
     */
    private function bootstrap(): void
    {
        $autoloader = require $this->root . '/vendor/autoload.php';

        foreach (glob($this->root . '/modules/*/composer.json') as $composer_file) {
            $json = json_decode((string)file_get_contents($composer_file), true);
            $dir  = dirname($composer_file);

            foreach (($json['autoload']['psr-4'] ?? []) as $namespace => $path) {
                $autoloader->addPsr4($namespace, "$dir/$path");
            }
        }

        CApp::getInstance()->startForCli('ox-seed:identities');
    }
}
