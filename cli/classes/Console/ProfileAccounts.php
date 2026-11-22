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
use Throwable;

/**
 * Un compte par PROFIL MÉTIER, avec un mot de passe utilisable.
 *
 * CE QUI MANQUAIT, mesuré sur une installation neuve le 2026-10-03 :
 *  - le dépôt livre DOUZE profils-modèles (`users.template` = '1') qui sont exactement les métiers
 *    attendus — Accueil, Hospitalisation, Chirurgie, Anesthésie, Secrétariat, Facturation,
 *    Direction, PMSI, Qualité, Surveillance de bloc, SI ;
 *  - le semis ne crée QUE des médecins : 22 comptes de type 13, et rien d'autre ;
 *  - et ces 22 comptes ont un MOT DE PASSE VIDE, donc personne ne peut se connecter avec eux.
 * Il était donc impossible d'ouvrir une session « infirmière » ou « admissions » pour voir ce que
 * ces profils donnent à l'écran. Cette commande pose un compte par profil, avec un mot de passe.
 *
 * ELLE NE TOUCHE JAMAIS UN COMPTE EXISTANT. Un identifiant déjà pris est SAUTÉ et signalé : poser
 * un mot de passe connu sur le compte d'une personne réelle serait une prise de contrôle, pas une
 * commodité. C'est le seul garde-fou qui compte ici, et il est inconditionnel — il n'y a pas de
 * drapeau pour le lever.
 *
 * LE MOT DE PASSE EST VALIDÉ AVANT TOUTE ÉCRITURE. La politique est configurable
 * (`admin CPasswordSpec strong_password_min_length`, caractères spéciaux…) : créer douze comptes
 * puis découvrir qu'aucun ne peut se connecter serait le pire résultat possible. On vérifie d'abord,
 * on écrit ensuite.
 *
 * ⚠ CES COMPTES PARTAGENT UN MOT DE PASSE CONNU. Ils sont destinés à un environnement de
 * démonstration ou d'enseignement. La commande le dit en clair à chaque exécution.
 */
class ProfileAccounts extends HospilibreCommand
{
    /** Préfixe des identifiants créés. Sert aussi à les reconnaître d'une exécution à l'autre. */
    private const USERNAME_PREFIX = 'profil.';

    /** @var SymfonyStyle */
    private $io;

    /** @var string */
    private $root;

    protected function configure(): void
    {
        $this
            ->setName('ox-accounts:profiles')
            ->setDescription('Crée un compte par profil métier, avec un mot de passe utilisable')
            ->addOption(
                'path',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Racine de la copie de travail',
                dirname(__DIR__, 3)
            )
            ->addOption(
                'password',
                null,
                InputOption::VALUE_OPTIONAL,
                'Mot de passe posé sur les comptes créés',
                'Hospilibre-2026!'
            )
            ->addOption(
                'group',
                'g',
                InputOption::VALUE_OPTIONAL,
                "Identifiant de l'établissement de rattachement (défaut : le premier qui a des services)"
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Montre ce qui serait fait, sans rien écrire');
    }

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io   = new SymfonyStyle($input, $output);
        $this->root = rtrim((string)$input->getOption('path'), '/');
        $password   = (string)$input->getOption('password');
        $dry_run    = (bool)$input->getOption('dry-run');

        $this->bootstrap();

        $this->io->title('Comptes de profil métier');

        $datasource = CSQLDataSource::get('std');

        if (!$datasource) {
            $this->io->error("La datasource « std » n'est pas joignable.");

            return self::FAILURE;
        }

        // VALIDÉ D'ABORD. Voir l'en-tête de classe : douze comptes injoignables seraient pires que
        // zéro compte.
        $refusal = $this->passwordRefusal($password);

        if ($refusal !== null) {
            $this->io->error("Mot de passe refusé par la politique de l'instance : {$refusal}");
            $this->io->writeln('Aucun compte n\'a été créé. Reprendre avec --password=…');

            return self::FAILURE;
        }

        $profiles = $this->profiles($datasource);

        if ($profiles === []) {
            $this->io->warning(
                'Aucun profil-modèle dans cette base (users.template = \'1\').'
                . ' Les profils sont posés par le setup du module admin.'
            );

            return self::FAILURE;
        }

        $function_id = $this->functionId($datasource, $input->getOption('group'));

        if ($function_id === null) {
            $this->io->error(
                "Aucune fonction trouvée pour rattacher les comptes : CMediusers::function_id est"
                . ' obligatoire. Créer au moins une fonction, ou préciser --group.'
            );

            return self::FAILURE;
        }

        $rows    = [];
        $created = 0;
        $skipped = 0;

        foreach ($profiles as $profile) {
            $username = self::USERNAME_PREFIX . self::slug($profile['user_username']);

            $existing = $this->existingAccount($datasource, $username);

            if ($existing !== null) {
                // SAUTÉ, JAMAIS ÉCRASÉ : on ne pose pas de mot de passe sur un compte existant.
                // Le rattachement au profil, lui, est RÉPARÉ s'il est absent — un compte créé par
                // cette commande lors d'un passage antérieur peut l'avoir perdu, et c'est
                // précisément arrivé : la première version attachait sur une clé vide.
                $note = 'identifiant déjà pris : mot de passe INCHANGÉ';

                if ((int)$existing['profile_id'] === 0 && !$dry_run) {
                    $failure = $this->attachProfile((int)$existing['user_id'], (int)$profile['user_id']);
                    $note   .= $failure === null ? ', profil rattaché' : ", profil NON rattaché ({$failure})";
                } elseif ((int)$existing['profile_id'] === 0) {
                    $note .= ', profil à rattacher (simulation)';
                }

                $rows[] = [$username, '—', $profile['user_username'], $note];
                $skipped++;

                continue;
            }

            if ($dry_run) {
                $rows[] = [$username, $password, $profile['user_username'], '(simulation)'];

                continue;
            }

            $outcome = $this->createAccount($profile, $username, $password, $function_id);
            $rows[]  = [$username, $outcome === null ? $password : '—', $profile['user_username'], $outcome ?? 'créé'];

            if ($outcome === null) {
                $created++;
            }
        }

        $this->io->table(['identifiant', 'mot de passe', 'profil', 'état'], $rows);

        $this->io->warning(
            'Ces comptes partagent un mot de passe CONNU. Ils sont destinés à une instance de'
            . " démonstration ou d'enseignement, pas à un établissement en exercice."
        );

        if ($dry_run) {
            $this->io->note('Simulation : rien n\'a été écrit.');

            return self::SUCCESS;
        }

        $this->io->success(sprintf('%d compte(s) créé(s), %d sauté(s).', $created, $skipped));

        // Ce qui reste à faire est dit ICI plutôt que laissé à deviner : le rattachement d'un
        // utilisateur à ses services d'exercice n'existe pas encore de façon durable
        // (CAffectationUserService porte une date et sert au roulement journalier). Ces comptes
        // héritent donc des droits de leur profil, sans périmètre de service.
        $this->io->writeln(
            'Les droits viennent du profil (users.profile_id). Le périmètre par service d\'exercice'
            . " n'est pas encore posé : ces comptes voient ce que leur profil autorise, partout."
        );

        return $created > 0 || $skipped === count($profiles) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Pourquoi ce mot de passe serait refusé, ou null s'il passe.
     *
     * On interroge la politique de l'instance au lieu de supposer une longueur minimale : elle est
     * configurable, et un défaut codé en dur ici mentirait sur une instance durcie.
     */
    private function passwordRefusal(string $password): ?string
    {
        try {
            $class = 'Hospilibre\\Admin\\CUser';

            if (!class_exists($class)) {
                return null;
            }

            $user = new $class();
            $user->_user_password = $password;

            $message = $user->checkProps();

            return $message === '' ? null : $message;
        } catch (Throwable $e) {
            // Une politique qu'on ne sait pas évaluer ne doit pas bloquer : on laisse l'écriture
            // décider, et son échec sera rapporté compte par compte.
            return null;
        }
    }

    /**
     * Les profils-modèles, par type.
     *
     * @return array<array<string, string>>
     */
    private function profiles(CSQLDataSource $datasource): array
    {
        // template = '1' EN CHAÎNE, et c'est décisif : la colonne est un enum('0','1'), si bien
        // qu'un « template = 1 » NUMÉRIQUE compare l'INDEX 1 et sélectionne la valeur '0' — soit
        // exactement l'inverse. Mesuré : 24 lignes en numérique, 12 en chaîne.
        return (array)$datasource->loadList(
            'SELECT user_id, user_username, user_type FROM users WHERE template = \'1\' ORDER BY user_type'
        );
    }

    /**
     * Le compte existant, ou null. Rend la ligne et non un booléen : son profil nous intéresse.
     *
     * @return array<string, string>|null
     */
    private function existingAccount(CSQLDataSource $datasource, string $username): ?array
    {
        $row = $datasource->loadHash(
            $datasource->prepare(
                'SELECT user_id, profile_id FROM users WHERE user_username = %',
                $username
            )
        );

        return empty($row) ? null : $row;
    }

    /**
     * Une fonction de rattachement : CMediusers::function_id est obligatoire.
     */
    private function functionId(CSQLDataSource $datasource, $group_id): ?int
    {
        if ($group_id) {
            $id = $datasource->loadResult(
                $datasource->prepare(
                    'SELECT function_id FROM functions_hospilibre WHERE group_id = % ORDER BY function_id LIMIT 1',
                    $group_id
                )
            );

            return $id ? (int)$id : null;
        }

        // Par défaut, l'établissement qui a des services : c'est celui qui porte une activité, et
        // non l'établissement technique par défaut.
        $id = $datasource->loadResult(
            'SELECT f.function_id FROM functions_hospilibre f
               JOIN service s ON s.group_id = f.group_id AND s.cancelled = \'0\'
              ORDER BY f.function_id LIMIT 1'
        );

        if ($id) {
            return (int)$id;
        }

        $id = $datasource->loadResult('SELECT function_id FROM functions_hospilibre ORDER BY function_id LIMIT 1');

        return $id ? (int)$id : null;
    }

    /**
     * Crée le compte, ou rend la raison de l'échec.
     */
    private function createAccount(array $profile, string $username, string $password, int $function_id): ?string
    {
        try {
            $class = 'Hospilibre\\Mediusers\\CMediusers';

            if (!class_exists($class)) {
                return 'CMediusers introuvable';
            }

            /** @var object $user */
            $user = new $class();

            // Les champs `_user_*` et non la table users directement : c'est le chemin qu'emploie
            // le semis, et le seul qui maintienne la cohérence entre les deux tables.
            $user->function_id      = $function_id;
            $user->_user_username   = $username;
            $user->_user_password   = $password;
            $user->_user_last_name  = $profile['user_username'];
            $user->_user_first_name = 'Profil';
            $user->_user_type       = (int)$profile['user_type'];
            $user->actif            = '1';

            $message = $user->store();

            if ($message) {
                return is_string($message) ? $message : 'échec de store()';
            }

            // Le rattachement au profil est ce qui donne ses droits au compte : sans lui, le compte
            // existe, se connecte, et ne voit rien de particulier.
            return $this->attachProfile((int)$user->_id, (int)$profile['user_id']);
        } catch (Throwable $e) {
            return get_class($e) . ' : ' . $e->getMessage();
        }
    }

    /**
     * Rattache un compte à son profil, ET VÉRIFIE QUE C'EST FAIT.
     *
     * PREMIÈRE VERSION DE CETTE MÉTHODE : un UPDATE sur `$user->user_id`, qui est vide sur un
     * CMediusers — sa clé est exposée par `_id`. L'UPDATE ne touchait donc aucune ligne, `exec()`
     * rendait vrai (zéro ligne modifiée n'est pas une erreur SQL), et la commande rapportait
     * « créé » pour onze comptes dont AUCUN n'avait de profil. Mesuré : 0 rattachement sur 11.
     *
     * C'est le défaut que ce dépôt passe la journée à corriger ailleurs — un succès qui affirme
     * plus que ce qui a été fait. On relit donc la valeur après écriture, au lieu de se fier au
     * retour de la requête.
     *
     * @return string|null La raison de l'échec, ou null si le rattachement est vérifié.
     */
    private function attachProfile(int $user_id, int $profile_id): ?string
    {
        if ($user_id === 0) {
            return 'identifiant du compte introuvable après écriture';
        }

        $datasource = CSQLDataSource::get('std');

        if (!$datasource) {
            return 'datasource std injoignable';
        }

        // MARQUEURS NUMEROTES, et c'est la cause du premier echec de cette methode.
        // CSQLDataSource::prepare() traite un « % » NU comme un alias du PREMIER argument : deux
        // « % » dans la meme requete recoivent donc tous deux la premiere valeur. Mesure de la
        // requete generee : « SET profile_id = '2' WHERE user_id = '2' » au lieu de user_id = 38.
        // Elle ne touchait aucune ligne, exec() rendait un PDOStatement — donc vrai — et rien ne le
        // disait. Les marqueurs %1 et %2 lient positionnellement.
        $datasource->exec(
            $datasource->prepare('UPDATE users SET profile_id = %1 WHERE user_id = %2', $profile_id, $user_id)
        );

        $stored = (int)$datasource->loadResult(
            $datasource->prepare('SELECT profile_id FROM users WHERE user_id = %', $user_id)
        );

        if ($stored !== $profile_id) {
            return sprintf('profil NON rattaché (lu : %s, attendu : %s)', $stored ?: 'vide', $profile_id);
        }

        return null;
    }

    private static function slug(string $value): string
    {
        $slug = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        $slug = strtolower((string)($slug === false ? $value : $slug));
        $slug = preg_replace('/[^a-z0-9]+/', '', $slug);

        return (string)$slug;
    }

    /**
     * Même amorçage que ox-modules:setup : le build Docker utilise « composer install --no-scripts »,
     * donc les namespaces PSR-4 des modules ne sont pas dans l'autoloader généré.
     *
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

        CApp::getInstance()->startForCli('ox-accounts:profiles');
    }
}
