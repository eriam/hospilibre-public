#!/bin/bash
set -e

ROOT=/var/www/html

# Créer les répertoires tmp nécessaires
mkdir -p "$ROOT/tmp/errors" "$ROOT/tmp/shared" "$ROOT/tmp/templates_c"
chown -R www-data:www-data "$ROOT/tmp"
chmod -R 775 "$ROOT/tmp"

# Le répertoire des pièces jointes, inscriptible par PHP.
#
# ⚠ IL NE L'ÉTAIT PAS, et aucun écran ne le disait. `files/` est un volume que CET ENTRYPOINT crée
# — il y écrit `files/deploy` et `files/seed` — et il le crée EN ROOT, puisque c'est sous root qu'il
# tourne. Le répertoire restait donc `root:root` en 755, et `www-data` ne pouvait rien y créer.
# `CFile::prepareDir()` échoue alors sur `files/private/…` avec un simple `trigger_error`, c'est-à-
# dire un avertissement dans le journal et un retour « Cannot create directory » : toute pièce
# jointe déposée par un utilisateur était perdue, et le seed ne pouvait pas en produire une seule.
#
# NON RÉCURSIF, ET C'EST VOULU : `chown -R` sur ce volume parcourrait à chaque démarrage tous les
# fichiers déjà déposés, dont les artefacts du semis. Seuls les deux répertoires que PHP doit
# pouvoir ouvrir sont traités ; les sous-répertoires que `CFile` crée lui-même naîtront déjà à lui.
# Root continue d'écrire `files/deploy` et `files/seed` sans gêne, les permissions ne s'appliquant
# pas à lui.
mkdir -p "$ROOT/files/private"
chown www-data:www-data "$ROOT/files" "$ROOT/files/private"
chmod 775 "$ROOT/files" "$ROOT/files/private"

# Identifiants de base : injectés depuis DB_HOST / DB_NAME / DB_USER / DB_PASS dans includes/config.php,
# AVANT tout accès à la base. Le fichier versionné n'en porte plus aucun : il portait ceux de staging,
# embarqués dans chaque image construite depuis le dépôt (y compris les images distribuées pour le
# cours MIAGE). Toute source déclarée sur l'hôte DB_HOST (std et référentiels du même serveur) les
# reçoit — cf. Ox\Cli\CConfigDbEnv, testé. Sans DB_HOST, rien n'est touché (config montée par
# l'exploitant). Idempotent.
if [ -n "${DB_HOST:-}" ]; then
    echo "[entrypoint] Identifiants de base injectés depuis l'environnement (hôte ${DB_HOST})..."
    ROOT_DIR="$ROOT" php -r '
require getenv("ROOT_DIR") . "/vendor/autoload.php";
$f = getenv("ROOT_DIR") . "/includes/config.php";
$c = is_file($f) ? file_get_contents($f) : "<?php\n";
$n = \Ox\Cli\CConfigDbEnv::apply($c, getenv("DB_HOST"), (string) getenv("DB_NAME"), (string) getenv("DB_USER"), (string) getenv("DB_PASS"));
if ($n !== $c) { file_put_contents($f, $n); }
' || { echo "[entrypoint] ERREUR: injection des identifiants de base impossible." >&2; exit 1; }
    chown www-data:www-data "$ROOT/includes/config.php" 2>/dev/null || true
fi

# Générer classmap.php si absent (ne nécessite pas la DB)
if [ ! -f "$ROOT/includes/classmap.php" ]; then
    echo "[entrypoint] Génération de classmap.php..."
    php -r "
\$autoloader = require '$ROOT/vendor/autoload.php';
foreach (glob('$ROOT/modules/*/composer.json') as \$f) {
    \$json = json_decode(file_get_contents(\$f), true);
    \$dir = dirname(\$f);
    foreach ((\$json['autoload']['psr-4'] ?? []) as \$ns => \$path) {
        \$autoloader->addPsr4(\$ns, \$dir . '/' . \$path);
    }
}
\Ox\Core\Cache::init('$ROOT');
\$r = \Ox\Core\CClassMap::getInstance()->buildClassMap();
echo \$r . PHP_EOL;
" 2>&1 || echo "[entrypoint] Avertissement: buildClassMap a échoué"
fi

# Générer config_dist.php si absent (ne nécessite pas la DB).
#
# SANS LUI, bin/console NE DÉMARRE PAS DU TOUT : CApp::checkMandatoryFiles() lève
# « The config definition file is not present. » avant même d'ouvrir la base, donc
# ox-modules:setup, ox-install:externals et ox-seed:hospi échouent tous — et l'échec était
# AVALÉ par les « || true » de ce script, si bien que le conteneur démarrait Apache et
# semblait sain. Constaté en construisant l'image de production et en la démarrant pour de
# vrai : c'était le SEUL fichier obligatoire manquant, les six autres étant déjà générés
# ci-dessus ou versionnés.
#
# Le Dockerfile ne peut pas le produire à la construction : tools/build_generated.php charge
# l'application, ce que l'étape de build ne permet pas (pas de base, pas de classmap encore).
# Sa place est donc ici, au premier démarrage, avec les autres.
if [ ! -f "$ROOT/includes/config_dist.php" ]; then
    echo "[entrypoint] Génération de config_dist.php..."
    php -r "
\$autoloader = require '$ROOT/vendor/autoload.php';
foreach (glob('$ROOT/modules/*/composer.json') as \$f) {
    \$json = json_decode(file_get_contents(\$f), true);
    \$dir = dirname(\$f);
    foreach ((\$json['autoload']['psr-4'] ?? []) as \$ns => \$path) {
        \$autoloader->addPsr4(\$ns, \$dir . '/' . \$path);
    }
}
\Ox\Core\Cache::init('$ROOT');
echo (new \Ox\Core\Config\CConfigDist())->build() . PHP_EOL;
" 2>&1 || echo "[entrypoint] Avertissement: génération de config_dist.php a échoué"
fi

# ÉCHEC IMMÉDIAT si le fichier manque toujours : tout ce qui suit passe par bin/console, et
# chaque étape est tolérante aux erreurs pour de bonnes raisons. Sans ce contrôle, un conteneur
# sans config_dist.php démarre Apache, paraît sain, et n'a en réalité aucun module configuré.
if [ ! -f "$ROOT/includes/config_dist.php" ]; then
    echo "[entrypoint] ERREUR: includes/config_dist.php est absent et n'a pas pu être généré." >&2
    echo "[entrypoint] Aucune commande bin/console ne peut démarrer sans lui." >&2
    exit 1
fi

chown www-data:www-data "$ROOT/includes/config_dist.php" 2>/dev/null || true

# Générer classref.php si absent (ne nécessite pas la DB)
if [ ! -f "$ROOT/includes/classref.php" ]; then
    echo "[entrypoint] Génération de classref.php..."
    php -r "
\$autoloader = require '$ROOT/vendor/autoload.php';
foreach (glob('$ROOT/modules/*/composer.json') as \$f) {
    \$json = json_decode(file_get_contents(\$f), true);
    \$dir = dirname(\$f);
    foreach ((\$json['autoload']['psr-4'] ?? []) as \$ns => \$path) {
        \$autoloader->addPsr4(\$ns, \$dir . '/' . \$path);
    }
}
\Ox\Core\Cache::init('$ROOT');
// Mêmes conditions que le hook composer post-autoload-dump (CComposerScript::postAutoloadDump) :
// getBackProps(), CGroups::get() et consorts court-circuitent leurs dépendances DB/classref
// quand ce flag est actif, exactement comme lors d'un vrai 'composer install' sans DB.
\Ox\Core\Composer\CComposerScript::\$is_running = true;
// Même filtre 'DSN type' que la génération des routes plus bas, et pour la même raison.
// CClassMap::buildClassRef() valide chaque ref de champ via CRefChecker, qui INSTANCIE la
// classe cible (CRefChecker::checkClassique(), 'new \$this->ref_spec->class'). Construire un
// modèle dont \$_spec->dsn pointe une source secondaire (sae, INSEE, hospi_diag, cim10,
// ccamV2, csarr, cdarr, ASIP, hl7v2, cisp, cluster, presta_ssr...) appelle
// CSQLDataSource::get(), qui fait trigger_error(E_USER_ERROR) dès que la conf n'a pas
// d'entrée 'db <dsn> dbtype'. Or buildClassRef() ne charge QUE includes/config_dist.php,
// qui ne définit jamais les sources secondaires (elles vivent dans includes/config.php) et
// ne pré-neutralise que 'std' (CSQLDataSource::\$dataSources['std'] = null, CClassMap.php:397).
// E_USER_ERROR est INTERCEPTABLE PAR UN HANDLER MAIS PAS PAR UN try/catch : sans ce
// handler, php s'arrête net en code 255 SANS RIEN AFFICHER, classref.php n'est pas écrit,
// et toute page finit en 500 sur « Invalid classref index : Ox\Core\CModelObject ».
set_error_handler(static function (\$no, \$str) {
    return strpos(\$str, 'DSN type') !== false;
}, E_ALL);
\$r = \Ox\Core\CClassMap::getInstance()->buildClassRef();
echo \$r . PHP_EOL;
" 2>&1 || echo "[entrypoint] Avertissement: buildClassRef a échoué"
fi

# Garde-fou : on refuse de démarrer sur un classref absent ou vide.
# L'ancien filet de sécurité écrivait ici un stub « <?php return []; ». C'était pire que
# l'absence de fichier : le stub satisfait le test « [ ! -f classref.php ] » (donc plus
# aucune tentative de régénération aux boots suivants), fait passer un échec de build pour
# un succès, et transforme l'erreur en fatale d'exécution obscure à la première lecture —
# CClassMap::getClassDefinition() lève « Invalid classref index : Ox\Core\CModelObject »
# dès CModule::loadModulesFromCache(), soit des 500 à corps vide sur TOUTES les pages.
# Mieux vaut un conteneur qui ne démarre pas, avec le message de build sous les yeux.
if [ ! -s "$ROOT/includes/classref.php" ] \
   || ! php -r 'exit(is_array($r = @require $argv[1]) && $r !== [] ? 0 : 1);' "$ROOT/includes/classref.php" 2>/dev/null; then
    # On SUPPRIME le fichier fautif avant de sortir. CClassMap::__construct() fait
    # « $this->classref = include $this->file_classref » dès que le fichier existe : un
    # classref vide ou tronqué empoisonne alors tout processus qui instancie CClassMap —
    # y compris la régénération elle-même, qui meurt sur un parse error avant d'avoir pu
    # réécrire le fichier. Le laisser en place rend la panne non réparable par un simple
    # redémarrage ; le retirer garantit que le boot suivant repart d'un état propre.
    rm -f "$ROOT/includes/classref.php"
    echo "[entrypoint] ERREUR: includes/classref.php absent, vide ou invalide — génération échouée." >&2
    echo "[entrypoint] Voir les messages de buildClassRef ci-dessus. Démarrage interrompu." >&2
    exit 1
fi

chown www-data:www-data "$ROOT/includes/classmap.php" "$ROOT/includes/classref.php" 2>/dev/null || true


# Générer all_routes.yml, all_services.yml, legacy_actions.php et version.php si absents
# (ne nécessite pas la DB). Sans all_routes.yml, le routeur Symfony lève une
# FileLocatorFileNotFoundException dès la construction de l'AppBar après connexion,
# ce qui fait échouer toute page authentifiée avec une 500 "Application died unexpectedly".
if [ ! -f "$ROOT/includes/all_routes.yml" ]; then
    echo "[entrypoint] Génération de all_routes.yml, all_services.yml, legacy_actions.php, version.php..."
    php -r "
\$autoloader = require '$ROOT/vendor/autoload.php';
foreach (glob('$ROOT/modules/*/composer.json') as \$f) {
    \$json = json_decode(file_get_contents(\$f), true);
    \$dir = dirname(\$f);
    foreach ((\$json['autoload']['psr-4'] ?? []) as \$ns => \$path) {
        \$autoloader->addPsr4(\$ns, \$dir . '/' . \$path);
    }
}
\Ox\Core\Cache::init('$ROOT');
\Ox\Core\Composer\CComposerScript::\$is_running = true;
set_error_handler(static function (\$no, \$str) {
    return strpos(\$str, 'DSN type') !== false;
}, E_ALL);
echo (new \Ox\Core\Kernel\Routing\RouteManager())->loadAllRoutes()->buildAllRoutes() . PHP_EOL;
echo (new \Ox\Core\Kernel\Services\ServicesManager())->buildAllServices() . PHP_EOL;
echo \Ox\Core\CClassMap::getInstance()->buildLegacyActions() . PHP_EOL;
echo \Ox\Core\Version\Builder::buildVersion() . PHP_EOL;
" 2>&1 || echo "[entrypoint] Avertissement: génération routes/services a échoué"
fi

chown www-data:www-data "$ROOT/includes/all_routes.yml" "$ROOT/includes/all_services.yml" "$ROOT/includes/legacy_actions.php" "$ROOT/includes/version.php" 2>/dev/null || true

# Installer les librairies JS vendor (lib/*, gitignored) si absentes : Scriptaculous,
# datepicker, livepipe, flotr... Sans ça, le bundle JS agrégé plante en plein milieu
# (ex. "Autocompleter is not defined"), ce qui empêche "Main" et tout le reste de se
# définir en aval — la page authentifiée reste blanche/cassée côté client.
if [ ! -d "$ROOT/lib/scriptaculous" ]; then
    echo "[entrypoint] Installation des librairies JS vendor (lib/*)..."
    php -r "
\$autoloader = require '$ROOT/vendor/autoload.php';
foreach (glob('$ROOT/modules/*/composer.json') as \$f) {
    \$json = json_decode(file_get_contents(\$f), true);
    \$dir = dirname(\$f);
    foreach ((\$json['autoload']['psr-4'] ?? []) as \$ns => \$path) {
        \$autoloader->addPsr4(\$ns, \$dir . '/' . \$path);
    }
}
\Ox\Core\Cache::init('$ROOT');
echo \Ox\Core\Libraries\CLibrary::installAll() . PHP_EOL;
" 2>&1 || echo "[entrypoint] Avertissement: installation des libs JS a échoué"
    chown -R www-data:www-data "$ROOT/lib" 2>/dev/null || true
fi

# Renommage de marque des tables SQL (users/functions/groups/files :
# *_mediboard -> *_hospilibre) AVANT le boot applicatif. Indispensable :
# ox-modules:setup bootstrap l'application via CApp::startForCli() ->
# authTestUser() -> CUser::loadRefMediuser(), qui interroge users_hospilibre
# (le nouveau $spec->table). Sur une install existante encore en *_mediboard,
# ce boot échoue ("table users_hospilibre doesn't exist") AVANT que la révision
# de migration CSetup n'ait pu renommer la table : blocage circulaire (la
# migration exige l'état qu'elle doit produire). On applique donc le RENAME en
# SQL brut ici, idempotent (seulement si _mediboard existe et _hospilibre pas
# encore). La révision CSetup correspondante (RENAME ... ignore_errors=true)
# devient alors un simple no-op de suivi de version. Sans effet sur les installs
# fraîches (tables créées directement en _hospilibre).
echo "[entrypoint] Renommage des tables de marque (*_mediboard -> *_hospilibre) si nécessaire..."
php -r '
$h=getenv("DB_HOST"); $n=getenv("DB_NAME"); $u=getenv("DB_USER"); $p=getenv("DB_PASS");
try {
  $pdo = new PDO("mysql:host=$h;dbname=$n", $u, $p);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  foreach (["users","functions","groups","files"] as $t) {
    $old = "{$t}_mediboard"; $new = "{$t}_hospilibre";
    $hasOld = $pdo->query("SHOW TABLES LIKE ".$pdo->quote($old))->fetch();
    $hasNew = $pdo->query("SHOW TABLES LIKE ".$pdo->quote($new))->fetch();
    if ($hasOld && !$hasNew) { $pdo->exec("RENAME TABLE `$old` TO `$new`"); echo "  $old -> $new\n"; }
  }
} catch (Throwable $e) { echo "  [warn] rename tables: ".$e->getMessage()."\n"; }
' || true

# INSTALLATION NEUVE (opt-in : HOSPILIBRE_FRESH_INSTALL=on) — images autonomes du cours MIAGE.
#
# Le reste de ce script suppose une base DÉJÀ installée (staging, prod) : sur une base vide,
# ox-modules:setup échoue d'emblée (« Table 'modules' doesn't exist ») et rien ne démarre —
# constaté au premier démarrage à froid de l'image du cours. On rejoue donc ici les étapes de
# dev/Docker/install.sh qui créent le schéma : ox-install:database (base + tables de
# cli/sql/hospilibre.sql) puis ox-db:update --install (schéma complet des modules).
#
# GARDE : uniquement si la table `modules` est absente. --delete supprime la base existante ;
# sur une base installée, cette étape ne doit JAMAIS tourner, même si la variable est posée.
# Exige un utilisateur capable de créer des bases (root dans le compose du cours).
if [ "${HOSPILIBRE_FRESH_INSTALL:-off}" = "on" ]; then
    has_schema=$(php -r '
try {
  $pdo = new PDO("mysql:host=".getenv("DB_HOST"), getenv("DB_USER"), getenv("DB_PASS"));
  $st = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ".$pdo->quote(getenv("DB_NAME"))." AND table_name = \"modules\"");
  echo $st->fetchColumn() > 0 ? "yes" : "no";
} catch (Throwable $e) { echo "error: ".$e->getMessage(); }
')
    case "$has_schema" in
        no)
            echo "[entrypoint] Installation neuve : création de la base et du schéma (base vide)..."
            php "$ROOT/bin/console" ox-install:database --config --delete --no-ansi -v \
                || { echo "[entrypoint] ERREUR: ox-install:database a échoué." >&2; exit 1; }
            php "$ROOT/bin/console" ox-db:update --config --install -n --no-ansi 2>&1 | tail -5 \
                || echo "[entrypoint] AVERTISSEMENT: ox-db:update terminé avec un avertissement."
            ;;
        yes) echo "[entrypoint] Installation neuve demandée mais schéma déjà présent : rien à faire." ;;
        *)   echo "[entrypoint] ERREUR: état de la base illisible ($has_schema)." >&2; exit 1 ;;
    esac
fi

# Installe les DICTIONNAIRES EXTERNES (CCAM, CIM-10, communes INSEE par défaut).
#
# Cette étape manquait : ox-install:externals n'était appelé NULLE PART — ni ici, ni dans le
# Dockerfile, ni dans la CI — et la configuration livrée ne déclare pas les datasources
# ccamV2 ni cim10. Conséquence directe : sur toute instance fraîche, l'application affichait
# les codes CCAM sans libellé, et surtout l'écriture d'un diagnostic CIM-10 sur un séjour
# provoquait une erreur FATALE — CSejour::check() appelle CCodeCIM10::get(), qui descend
# jusqu'à CSQLDataSource::get('cim10') et déclenche un E_USER_ERROR quand la source n'est pas
# déclarée, ce que ni @ ni try/catch n'interceptent.
#
# --declare ajoute les déclarations manquantes à includes/config.php en reprenant l'hôte et
# les identifiants de « db std » : les dictionnaires vivent sur le même serveur. Le fichier
# est chargé APRÈS config_dist.php, donc une déclaration posée par un exploitant dans la
# config montée l'emporte toujours — et --declare la détecte et s'abstient.
#
# Idempotent : une base déjà peuplée est reconnue et conservée. Le premier démarrage coûte
# environ une minute (la CCAM pèse 174 Mo décompressés), les suivants quelques requêtes.
#
# Non bloquant, mais pas silencieux : le tableau de la commande dit source par source ce qui
# a été fait, là où l'absence d'un dictionnaire ne se voyait jusqu'ici qu'à l'usage.
DICTIONARIES="${HOSPILIBRE_DICTIONARIES:-ccamV2,cim10,INSEE}"
if [ -n "$DICTIONARIES" ]; then
    echo "[entrypoint] Dictionnaires externes ($DICTIONARIES)..."
    php "$ROOT/bin/console" ox-install:externals --declare --import --only="$DICTIONARIES" \
        || echo "[entrypoint] AVERTISSEMENT: installation des dictionnaires incomplète, voir le tableau ci-dessus." >&2
fi

# Installe, active et met à jour le socle de modules par défaut (system,
# admin, mediusers, dossier patient, etc.) selon un profil d'établissement
# (HOSPILIBRE_PROFILE : aigu|subaigu|ssr|complet, défaut complet) via
# bin/console ox-modules:setup (cli/classes/Console/ModulesSetup.php).
#
# Cette commande appelle CModule/CSetup directement en boot CLI
# (CApp::startForCli), sans passer par une session HTTP authentifiée.
# Remplace l'ancienne automatisation à base de curl + do_manage_module.php :
# celle-ci nécessitait un mot de passe admin (ADMIN_UPGRADE_PASSWORD), un
# jeton de connexion dynamique et une session web fonctionnelle — trois
# points de fragilité qui ont chacun causé une panne distincte en
# production (login jamais abouti faute du bon jeton, mot de passe absent).
# La logique de convergence (CSetup::upgrade() s'arrête silencieusement sur
# toute dépendance inter-module non satisfaite, il faut donc répéter les
# passages jusqu'à stabilisation) vit maintenant dans la commande elle-même,
# testable comme le reste du code plutôt qu'en bash.
#
# RÉPÉTÉ JUSQU'À SUCCÈS, et non appelé une seule fois. La commande itère déjà en interne
# (MAX_ROUNDS), mais elle peut épuiser ses passages sans converger et rendre FAILURE — son propre
# message le dit : « relancer la commande peut suffire si une dépendance a été satisfaite
# entre-temps ». Mesuré sur une installation de production neuve : au premier appel, dPpatients
# n'était même pas enregistré dans la table `modules` ; au second, il l'était en 4.92. Un seul
# appel laissait donc une instance sans dossier patient — et le « || true » faisait passer ça pour
# un succès, Apache démarrait, et rien ne le signalait avant qu'une commande ne refuse de tourner.
echo "[entrypoint] Configuration des modules (profil : ${HOSPILIBRE_PROFILE:-complet})..."

# SORTIE DURABLE ET LISIBLE PAR MACHINE, parce que « le dire » ne suffit pas.
#
# Mesure d'infra sur l’instance de démonstration : le journal Jenkins du déploiement #101 comptait ZÉRO erreur sur
# 5 002 lignes, alors que le conteneur en contenait quinze — dont celle qui bloquait le module
# system depuis des jours. Le pipeline passe par « compose up -d », donc tout l'entrypoint
# s'exécute dans un conteneur détaché dont le journal n'arrive jamais dans celui de la CI.
# L'avertissement plus bas était donc émis dans le vide : nous avons cherché pendant deux jours
# dans le seul journal qui ne pouvait pas le contenir.
#
# On ne fait pas échouer le conteneur pour autant (voir plus bas, l'image doit rester
# démarrable), mais le signal atterrit désormais là où le pipeline peut le lire :
#   - files/deploy/modules-setup.log    : la sortie complète, y compris la ligne « Rapport : ... »
#   - files/deploy/modules-setup.status : ok|failed, en clé=valeur
# files/ et non tmp/, pour la même raison que les artefacts du semis : tmp/ vit dans la couche
# écrivable du conteneur et disparaît à chaque recréation, donc à chaque déploiement.
#
# Le fichier d'état est écrit DANS LES DEUX CAS : « absent » ne doit jamais pouvoir se lire
# comme « tout va bien ».
SETUP_DIR="$ROOT/files/deploy"
mkdir -p "$SETUP_DIR" 2>/dev/null || true
SETUP_LOG="$SETUP_DIR/modules-setup.log"
: > "$SETUP_LOG" 2>/dev/null || true

modules_setup_ok=0
for attempt in 1 2 3; do
    # « ( set -o pipefail ; ... ) » : sans pipefail, l'état du tube serait celui de tee, donc
    # TOUJOURS zéro, et l'échec du setup passerait pour un succès — exactement le défaut qu'on
    # corrige ici. Le sous-shell cantonne l'option pour ne rien changer au reste du script, et le
    # « if » protège de set -e. Vérifié dans les deux sens avec un bouchon avant d'être écrit.
    if ( set -o pipefail; php "$ROOT/bin/console" ox-modules:setup --profile="${HOSPILIBRE_PROFILE:-complet}" -v 2>&1 | tee -a "$SETUP_LOG" ); then
        modules_setup_ok=1
        echo "[entrypoint] Modules configurés (passage $attempt)."
        break
    fi

    echo "[entrypoint] Passage $attempt incomplet, nouvelle tentative..."
done

{
    if [ "$modules_setup_ok" -eq 1 ]; then
        echo "status=ok"
    else
        echo "status=failed"
    fi
    echo "date=$(date -Is 2>/dev/null || date)"
    echo "profile=${HOSPILIBRE_PROFILE:-complet}"
    echo "log=files/deploy/modules-setup.log"
} > "$SETUP_DIR/modules-setup.status" 2>/dev/null || true

# Pas d'échec du conteneur : certains modules ne convergeront jamais parce que leur dépendance
# n'est pas distribuée dans ce fork (observationResult, par exemple), et refuser de démarrer pour
# autant rendrait l'image inutilisable. Mais on le DIT, au lieu de l'avaler.
if [ "$modules_setup_ok" -eq 0 ]; then
    echo "[entrypoint] AVERTISSEMENT: des modules n'ont pas atteint leur version cible après 3 passages." >&2
    echo "[entrypoint] Voir le tableau ci-dessus. Les commandes qui en dépendent refuseront de tourner." >&2
    echo "[entrypoint] État durable : files/deploy/modules-setup.status (status=failed)" >&2
    echo "[entrypoint] Sortie complète : files/deploy/modules-setup.log" >&2
fi

# Charge les données de référence bundlées (nomenclatures : CIM10 OMS + variantes
# GM/ATIH/CISP/DRC, CCAM, CSARR, CDARR, SAE ; LPP/HL7 si actifs) des modules
# actifs, si absentes. Idempotent (sentinelle de complétion : marquée seulement
# après un import réussi) : ne tourne qu'au 1er install, skip instantané ensuite.
# Non bloquant (|| true) : un échec d'import de référentiel ne doit pas empêcher
# le boot. S'exécute APRÈS ox-modules:setup (modules et schéma doivent exister).
# NB : sur une DB fraîche, CIM10 + CCAM (base.sql ≈ 174 Mo) rendent ce 1er boot
# long (plusieurs minutes) ; sans effet sur les boots suivants (DB persistée).
echo "[entrypoint] Import des données de référence (nomenclatures)..."
php "$ROOT/bin/console" ox-modules:import-data -v || true

# Simulation (opt-in, HOSPILIBRE_SIMULATION) : NE TOURNE PLUS ICI. Elle est lancée en arrière-plan,
# sous www-data, juste avant Apache (voir « Cycle de vie de la simulation » en fin de script).

# Semis d'hospitalisation (opt-in) : ox-seed:hospi, l'autre moteur de peuplement — celui qui
# produit une cohorte exportable (patients, séjours, affectations, interventions, codage CCAM).
# Activé par HOSPILIBRE_SEED_PROFILE (small|medium|large|xl) ; absent = rien ne tourne.
#
# EN ARRIÈRE-PLAN, ET CE N'EST PAS UN DÉTAIL. Un medium prend environ 33 minutes (mesuré sur le
# environnement de cours : 32 min 53 s pour 8 324 patients et 20 809 séjours). Or le pipeline sonde la
# readiness HTTP pendant 150 s au plus avant de déclarer le déploiement en échec. Semer en ligne
# ferait donc échouer tous les déploiements dès que l'option est active. Apache démarre d'abord,
# le semis se poursuit à côté et journalise dans files/seed/seed-boot.log.
#
# CONSÉQUENCE ASSUMÉE : le semis écrit pendant que l'application sert. ox-seed:hospi prévient
# lui-même qu'il « suppose qu'aucune autre écriture n'a lieu » — acceptable sur une instance de
# démonstration, à ne pas activer sur une instance en service réel.
if [ -n "${HOSPILIBRE_SEED_PROFILE:-}" ]; then
    (
        # DANS files/, PAS DANS tmp/, ET C'EST ESSENTIEL. Mesuré par infra sur l’instance de démonstration : tmp/ vit dans
        # la couche ÉCRIVABLE DU CONTENEUR, pas sur un volume — les seuls montages sont
        # config_dist.php, config_overload.php et le volume app_files -> files/. Donc tmp/ survit à
        # un « restart » mais est DÉTRUIT à chaque « compose up -d », c'est-à-dire à chaque
        # déploiement.
        #
        # Deux conséquences, et la seconde est la pire :
        #   - cohorte.csv, le livrable, serait effacé par le build suivant, sans erreur ni trace ;
        #   - le marqueur d'achèvement disparaîtrait AUSSI, alors que les objets tracés restent en
        #     base. La garde 2 refuserait alors « complétude INCONNUE » sur un semis pourtant
        #     COMPLET — le marqueur serait inutile précisément dans le cas qu'il doit couvrir.
        #
        # files/ est un volume : les trois artefacts y survivent à une recréation. Le résultat vit
        # donc aussi longtemps qu'il sert, ce qui n'était pas le cas.
        SEED_DIR="$ROOT/files/seed"
        mkdir -p "$SEED_DIR" 2>/dev/null || true
        SEED_LOG="$SEED_DIR/seed-boot.log"
        SEED_STATE="$SEED_DIR/seed-boot.state"

        # EN AJOUT, JAMAIS EN ECRASEMENT. Le « : > "$SEED_LOG" » d'origine vidait le journal a
        # CHAQUE demarrage. Constate sur l’instance de démonstration le 2026-10-03 : un semis de 29,5 minutes avait produit
        # 82 974 octets de journal — tableau de statistiques, plage d'IPP, detail des 728 patients
        # sans sejour, commande de rejeu a l'identique — et le deploiement suivant l'a reduit a
        # 88 octets en douze minutes. Le livrable (cohorte.csv) a survecu ; LA PREUVE DE SA
        # PRODUCTION a ete detruite. Et c'est le saut lui-meme, donc la garde fonctionnant
        # correctement, qui l'a ecrase.
        #
        # C'est la meme erreur que le marqueur dans tmp/, deplacee d'un cran : persister le FICHIER
        # ne sert a rien si on le reouvre en mode truncate. Un journal qui ne survit pas au
        # redemarrage suivant ne documente que le dernier boot, pas le travail.
        #
        # L'ajout fait croitre le fichier a chaque demarrage, de 88 octets quand le semis est saute.
        # C'est le bon compromis : la seule alternative sans perte serait un fichier horodate par
        # execution, qui disperserait l'historique au lieu de le garder lisible d'un coup.
        {
            echo "===================================================================="
            echo "[seed] demarrage du conteneur : $(date -Is 2>/dev/null || date)"
        } >> "$SEED_LOG" 2>/dev/null || true

        seed_say() { echo "[seed] $1" | tee -a "$SEED_LOG"; }

        # Compte les objets tracés, en distinguant « table absente » de « table vide ».
        # -1 = la table n'existe pas ; 0 ou plus = son nombre de lignes. La distinction est
        # essentielle ici : elle sépare deux situations aux conséquences opposées.
        # AUCUNE APOSTROPHE dans ce code PHP : il est passé entre apostrophes au shell, et une
        # apostrophe n'y est PAS échappable — elle terminerait la chaîne. D'où information_schema
        # et une requête préparée plutôt qu'un « SHOW TABLES LIKE 'fixtures_reference' ».
        tracked=$(php -r '
            $h = getenv("DB_HOST"); $n = getenv("DB_NAME");
            $u = getenv("DB_USER"); $p = getenv("DB_PASS");
            try {
                $pdo = new PDO("mysql:host=$h;dbname=$n", $u, $p);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
                $st->execute(["fixtures_reference"]);
                if (!(int) $st->fetchColumn()) { echo "-1"; exit; }
                echo (int) $pdo->query("SELECT COUNT(*) FROM fixtures_reference")->fetchColumn();
            } catch (Throwable $e) { echo "-1"; }
        ' 2>/dev/null || echo -1)

        # Réduit à un entier signé : une sortie inattendue (avertissement PHP, chaîne vide) ferait
        # échouer les comparaisons numériques, et set -e tuerait le sous-shell avant le semis.
        case "$tracked" in
            -[0-9]*|[0-9]*) : ;;
            *) tracked=-1 ;;
        esac

        # GARDE 1 — PAS DE TRAÇAGE, PAS DE SEMIS. Si fixtures_reference n'existe pas, le semis
        # écrit des objets que RIEN n'enregistre : ni --purge ni un DELETE borné ne pourront les
        # défaire. C'est arrivé sur l’instance de démonstration, où le module `system` restait sous la révision
        # 1.3.7 qui crée cette table : deux déploiements y ont laissé deux établissements
        # orphelins. Refuser de commencer est la seule issue honnête.
        if [ "$tracked" -lt 0 ] 2>/dev/null; then
            seed_say "ANNULÉ : la table fixtures_reference n'existe pas, donc rien ne pourrait être"
            seed_say "tracé — ni défait. Elle est créée par la révision 1.3.7 du module system :"
            seed_say "vérifier que ox-modules:setup l'amène bien à sa version cible avant de semer."
            exit 0
        fi

        # GARDE 2 — NE JAMAIS SAUTER UN SEMIS PARTIEL EN SILENCE.
        #
        # LE PIÈGE, et il est structurel. La séquence de déploiement démarre le conteneur DEUX
        # fois : « compose up -d » le recrée (entrypoint, donc semis), puis ~35 s plus tard un
        # « compose restart app » INCONDITIONNEL le relance (entrypoint, donc semis à nouveau).
        # C'est le filet broken-cache des déploiements #69/#71. Un semis de 33 minutes lancé au
        # premier passage est donc TUÉ par le second, après avoir écrit une partie de ses objets.
        # La version précédente de cette sentinelle voyait alors des lignes tracées et sautait en
        # annonçant « déjà semé : rien à faire » : un déploiement réussi produisait de façon FIABLE
        # une base à moitié semée présentée comme faite.
        #
        # On distingue donc trois états, et aucun ne se tait :
        #   fichier d'état « completed »  -> semis achevé, on saute (c'est le seul saut légitime) ;
        #   fichier d'état sans achèvement -> interrompu DANS CE CONTENEUR, on refuse en le disant ;
        #   objets tracés sans fichier d'état -> complétude INCONNUE (tmp/ est reparti à zéro avec
        #     le conteneur, la base non), on refuse en le disant.
        if [ -f "$SEED_STATE" ] && grep -q '^completed' "$SEED_STATE" 2>/dev/null; then
            seed_say "déjà semé et ACHEVÉ ($(cat "$SEED_STATE")) : rien à faire."
            exit 0
        fi

        if [ -f "$SEED_STATE" ]; then
            seed_say "REFUS : un semis précédent a été INTERROMPU dans ce conteneur"
            seed_say "($(cat "$SEED_STATE")), et $tracked objet(s) sont tracés. Reprendre par-dessus"
            seed_say "mélangerait deux jeux de données. Purger (ox-seed:hospi --only-purge) ou"
            seed_say "supprimer les objets tracés, puis redémarrer."
            exit 0
        fi

        if [ "$tracked" -gt 0 ] 2>/dev/null; then
            seed_say "REFUS : la base porte déjà $tracked objet(s) tracés, et aucun marqueur"
            seed_say "d'achèvement n'accompagne ce conteneur — la complétude est donc INCONNUE."
            seed_say "Je ne sème pas par-dessus et je ne prétends pas que c'est fait. Contrôler le"
            seed_say "jeu existant, puis purger ou laisser en l'état en connaissance de cause."
            exit 0
        fi

        # GARDE MÉMOIRE. Un medium a besoin d'environ 1,36 Gio de RSS (mesuré). Sur un hôte trop
        # juste — 3,9 Go sans swap et earlyoom au seuil de 10 %, cas du banc de développement — le process est
        # abattu par SIGTERM vers la 9e minute, APRÈS avoir écrit des milliers d'objets. Et sur une
        # instance qui a importé la CIM-10, ces objets ne sont PAS récupérables : la collision de
        # table `note` casse la suppression par la console, et --purge efface ses propres
        # références sans rien supprimer. Mieux vaut refuser de commencer que laisser une base
        # à moitié peuplée et intraçable.
        need_mb=2048
        case "${HOSPILIBRE_SEED_PROFILE}" in
            small) need_mb=512 ;;
            medium) need_mb=2048 ;;
            *) need_mb=3072 ;;
        esac

        avail_mb=$(awk '/MemAvailable/ {printf "%d", $2/1024}' /proc/meminfo 2>/dev/null || echo 0)

        if [ "${avail_mb:-0}" -lt "$need_mb" ]; then
            seed_say "ANNULÉ : ${avail_mb} Mo disponibles pour un profil ${HOSPILIBRE_SEED_PROFILE}"
            seed_say "qui en demande ~${need_mb}. Un semis tué en cours de route laisserait une base"
            seed_say "à moitié peuplée que --purge ne sait pas défaire. Redimensionner l'hôte, ou"
            seed_say "choisir un profil plus petit."
            exit 0
        fi

        # --now EST DÉTERMINANT. Sans lui, l'instant de référence est l'horloge de la machine :
        # PatientBuilder en dérive les dates de naissance et SeedContext en fait glisser toute la
        # fenêtre, si bien que la même graine rend des données DIFFÉRENTES d'un jour à l'autre. Un
        # semis non rejouable n'est pas comparable à un témoin.
        # UN TABLEAU, ET NON UNE CHAÎNE. Une chaîne d'arguments développée sans guillemets est
        # redécoupée par le shell sur les espaces : HOSPILIBRE_SEED_NOW vaut « 2026-09-30
        # 08:00:00 », donc « --now=2026-09-30 » et « 08:00:00 » devenaient DEUX arguments, et
        # Symfony refusait le second comme argument positionnel :
        #   No arguments expected for "ox-seed:hospi" command, got "08:00:00".
        # Un tableau préserve les frontières d'arguments, quel que soit le contenu des valeurs.
        # La version précédente portait un « shellcheck disable=SC2086 » : j'avais désactivé le
        # contrôle qui existait précisément pour attraper ça.
        seed_args=(--profile="${HOSPILIBRE_SEED_PROFILE}" -n --export="$SEED_DIR/cohorte.csv")

        if [ -n "${HOSPILIBRE_SEED_DECOR:-}" ]; then
            seed_args+=(--decor="${HOSPILIBRE_SEED_DECOR}")
        fi

        if [ -n "${HOSPILIBRE_SEED_SEED:-}" ]; then
            seed_args+=(--seed="${HOSPILIBRE_SEED_SEED}")
        fi

        if [ -n "${HOSPILIBRE_SEED_NOW:-}" ]; then
            seed_args+=(--now="${HOSPILIBRE_SEED_NOW}")
        else
            seed_say "AVERTISSEMENT : HOSPILIBRE_SEED_NOW absent. Le jeu produit ne sera pas"
            seed_say "rejouable : même graine, autre jour, autres données."
        fi

        # GARDE 3 — LAISSER PASSER LA FENÊTRE DE REDÉMARRAGE DU DÉPLOIEMENT.
        #
        # Le déploiement recrée le conteneur puis le redémarre ~35 s plus tard (sleep 30 + relevé
        # de diagnostic + « restart app »). Démarrer un semis de 33 minutes tout de suite garantit
        # qu'il sera tué. On attend donc que cette fenêtre soit passée : le semis du premier
        # passage meurt pendant l'attente, SANS avoir rien écrit, et celui du second passage
        # démarre au calme.
        #
        # C'est une hypothèse de TEMPS, donc faillible : si le redémarrage survenait plus tard que
        # l'attente, le semis serait tué en cours. La garde 2 ci-dessus rattrape alors le coup en
        # refusant de sauter un partiel en silence — l'attente évite le cas courant, la garde 2
        # couvre le reste. Le vrai correctif structurel serait de ne pas semer depuis l'entrypoint
        # du tout, mais depuis une étape de déploiement dédiée ; ça touche le Jenkinsfile.
        seed_delay=${HOSPILIBRE_SEED_DELAY:-150}

        if [ "$seed_delay" -gt 0 ] 2>/dev/null; then
            seed_say "attente de ${seed_delay}s pour laisser passer le redémarrage du déploiement."
            sleep "$seed_delay"
        fi

        # Rien n'a été écrit avant cette ligne : on peut donc marquer « démarré » maintenant, et
        # non avant l'attente. Un semis tué PENDANT l'attente ne laisse ainsi aucun marqueur, donc
        # aucune fausse alerte « interrompu » au passage suivant.
        echo "started $(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$SEED_STATE"

        seed_say "profil ${HOSPILIBRE_SEED_PROFILE}, ${avail_mb} Mo disponibles — démarrage."
        seed_say "journal : files/seed/seed-boot.log ; cohorte : files/seed/cohorte.csv (volume)"

        # « || seed_code=$? » ET NON « ; code $? ». set -e est actif : sans cette capture, un semis
        # qui échoue tue le sous-shell AVANT le rechown ci-dessous, tmp/ reste à root, et Apache
        # perd ses locks — soit précisément la panne de boot que ce fichier documente plus haut.
        # Vérifié : « set -e; false; echo $? » ne s'exécute pas.
        seed_code=0
        php -d memory_limit=3G "$ROOT/bin/console" ox-seed:hospi "${seed_args[@]}" >> "$SEED_LOG" 2>&1 \
            || seed_code=$?
        seed_say "terminé, code $seed_code."

        # Le marqueur d'ACHÈVEMENT n'est écrit QU'ICI, et seulement si le semis a rendu 0. C'est le
        # seul état qui autorise un saut silencieux au démarrage suivant.
        if [ "$seed_code" -eq 0 ]; then
            echo "completed $(date -u +%Y-%m-%dT%H:%M:%SZ) code=0" > "$SEED_STATE"
        else
            echo "failed $(date -u +%Y-%m-%dT%H:%M:%SZ) code=$seed_code" > "$SEED_STATE"
        fi

        # Le semis tourne en root (l'entrypoint l'est) et écrit dans tmp/ ET dans files/seed/ :
        # sans ce rechown, Apache — qui sert en www-data — perd la main sur ses locks et son cache,
        # et les artefacts du semis deviennent illisibles pour l'application. Le rechown général de
        # l'entrypoint a lieu AVANT ce bloc, puisqu'il est asynchrone : c'est donc à lui de le refaire.
        chown -R www-data:www-data "$ROOT/tmp" "$SEED_DIR" 2>/dev/null || true
    ) &
fi

# Reprise de propriété de tmp/ JUSTE AVANT de passer la main à Apache.
# Le chown du début de script ne suffit pas : toutes les étapes ci-dessus tournent en root
# (php -r de génération, ox-install:*, ox-modules:setup, ox-modules:import-data,
# simulate:bootstrap) et créent au passage des sous-dossiers de travail
# — tmp/locks, tmp/locales, tmp/templates_c, tmp/HTML... — qui
# héritent de root:root 755. Apache, lui, sert en www-data : il ne peut alors plus écrire
# dans tmp/locks, et la cascade est silencieuse et trompeuse :
#   touch(tmp/locks/...-mutex-locales-build) refusé  (CMbFileMutex::touchFile)
#     -> CAppUI::loadLocales() ne peut pas prendre son verrou et ne construit pas son cache
#     -> les fichiers de locales sont require() en pleine page, dont un qui porte un BOM
#        UTF-8 (modules/dPpatients/locales/fr.php:1) -> 3 octets émis avant tout header
#     -> « Cannot modify header information - headers already sent » sur TOUS les header()
#        d'index.php (lignes 290-301), puis mort avant CApp::rip()
#     -> CApp::checkPeace() répond « HTTP/1.1 500 Application died unexpectedly »,
#        corps vide (le buffer de CHTMLResourceLoader::initOutput est jeté).
# Rien n'apparaît dans les logs PHP (log_errors=Off) ni ailleurs : la journalisation
# d'erreurs est routée vers Elasticsearch (config 'elastic error-log', es01:9200), absent
# du déploiement. D'où un 500 à corps vide indistinguable de la panne de classref.
echo "[entrypoint] Reprise de propriété de tmp/ pour www-data..."
chown -R www-data:www-data "$ROOT/tmp"
chmod -R 775 "$ROOT/tmp"

# files/deploy/ est produit par les étapes ci-dessus, qui tournent en root. Sans ce rechown, les
# traces de déploiement appartiendraient à root dans un volume servi par www-data : l'application
# ne pourrait pas les relire, et un prochain démarrage ne pourrait pas les réécrire.
if [ -d "$ROOT/files/deploy" ]; then
    chown -R www-data:www-data "$ROOT/files/deploy" 2>/dev/null || true
fi

# Cycle de vie de la simulation (bin/sim-lifecycle.sh) : semis J0, rattrapage, tick. EN ARRIÈRE-PLAN
# (un semis CHU prend jusqu'à une heure : Apache sert pendant ce temps) et SOUS www-data, lancé
# APRÈS la reprise de propriété de tmp/ ci-dessus — sinon ses fichiers de travail naîtraient root.
# Sa sortie standard (lignes [sim]) remonte dans « docker logs » ; le détail va dans
# files/seed/sim-boot.log (volume : survit aux recréations du conteneur).
if [ "${HOSPILIBRE_SIMULATION:-off}" != "off" ]; then
    mkdir -p "$ROOT/files/seed" && chown www-data:www-data "$ROOT/files/seed"
    echo "[entrypoint] Simulation « ${HOSPILIBRE_SIMULATION} » : cycle de vie lancé en arrière-plan (www-data)."
    runuser -u www-data -- bash "$ROOT/bin/sim-lifecycle.sh" &
fi

exec apache2-foreground
