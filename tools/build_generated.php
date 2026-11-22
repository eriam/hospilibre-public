<?php

/**
 * build_generated.php — Génère les fichiers includes/* habituellement produits par
 * le hook composer post-autoload-dump (HospiLibre, Phase 1).
 *
 * POURQUOI : pendant `composer install`, le hook postAutoloadDump construit le classmap
 * en faisant des include_once de toutes les classes. Or l'autoloader actif DANS le process
 * composer n'expose pas les namespaces de modules fusionnés dynamiquement
 * (Hospilibre\*), ce qui fait échouer la résolution (ex. IIndexableObject) — alors que
 * dans un process PHP neuf chargeant vendor/autoload.php, tout se résout.
 *
 * On installe donc les dépendances avec `composer install --no-scripts`, puis on rejoue
 * ici les mêmes étapes via les API publiques, dans un process où vendor/autoload.php est
 * pleinement actif. Reproduit CComposerScript::postAutoloadDump().
 *
 * Usage : php tools/build_generated.php
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_STRICT);

$root       = dirname(__DIR__);
$autoloader = require $root . '/vendor/autoload.php';

// FUSION DES NAMESPACES DE MODULES, sans quoi rien de ce qui suit ne peut fonctionner.
//
// Le docblock ci-dessus affirmait qu'« un process PHP neuf chargeant vendor/autoload.php »
// résolvait tout. C'était faux : ce sont les hooks composer qui fusionnent les PSR-4 des
// modules dans vendor/composer/autoload_psr4.php (CComposerScript::preAutoloadDump), et
// l'étape 1 de dev/Docker/install.sh installe précisément avec --no-scripts. L'autoloader
// ne connaissait donc AUCUN namespace Hospilibre\* — vérifié sur une installation neuve :
// zéro occurrence dans autoload_psr4.php — et quatre générations sur huit échouaient, dont
// le classmap et le classref, sur « Interface Hospilibre\Search\IIndexableObject not found ».
//
// docker-entrypoint.sh fait déjà cette fusion avant d'appeler les mêmes constructeurs ;
// elle manquait seulement ici.
foreach (glob($root . '/modules/*/composer.json') as $module_composer) {
    $manifest = json_decode(file_get_contents($module_composer), true);

    foreach (($manifest['autoload']['psr-4'] ?? []) as $namespace => $relative) {
        $autoloader->addPsr4($namespace, dirname($module_composer) . '/' . $relative);
    }
}

use Ox\Core\Cache;
use Ox\Core\CClassMap;
use Ox\Core\Config\CConfigDist;
use Ox\Core\Kernel\Routing\RouteManager;
use Ox\Core\Kernel\Services\ServicesManager;
use Ox\Core\OpenApi\COpenApiManager;
use Ox\Core\Version\Builder;
use Ox\Core\Libraries\CLibrary;
use Ox\Core\Composer\CComposerScript;

// Reproduit le contexte composer : ce flag neutralise getBackProps() pendant la
// construction des modèles (sinon getClassRef() est appelé avant que le classref soit
// complet → "Invalid classref index : Ox\Core\CModelObject"). Cf. CModelObject::getBackProps().
CComposerScript::$is_running = true;

// buildClassRef() instancie tous les modèles par réflexion. Certains se lient à des
// sources de données externes (sae, bcb, hl7v2, maidis…) non configurées dans une
// install de base : CSQLDataSource::get() lève alors un E_USER_ERROR « Undefined DSN
// type », fatal en CLI sans gestionnaire. On le neutralise (l'app le tolère aussi via son
// propre handler) : get() renverra null et la génération se poursuit.
set_error_handler(static function (int $no, string $str): bool {
    return strpos($str, 'DSN type') !== false; // true = avalé ; false = comportement normal
}, E_ALL);

Cache::init($root);

$failures = 0;

/** Exécute une étape isolée ; $critical=true => l'échec fait sortir en erreur. */
function step(string $label, callable $fn, bool $critical = false): void
{
    global $failures;
    echo "==> $label ... ";
    try {
        $msg = $fn();
        echo "OK" . (is_string($msg) && $msg !== '' ? " — $msg" : "") . "\n";
    } catch (\Throwable $e) {
        echo ($critical ? "ÉCHEC CRITIQUE" : "ignoré") . " : " . $e->getMessage() . "\n";
        if ($critical) {
            $failures++;
        }
    }
}

// Étapes critiques pour le filet (classmap = autoload/check_refs, config_dist = install).
step('classmap.php', fn() => CClassMap::getInstance()->buildClassMap(), true);
step('config_dist.php', fn() => (new CConfigDist())->build(), true);
step('classref.php', fn() => CClassMap::getInstance()->buildClassRef(), true);
step('legacy_actions.php', fn() => CClassMap::getInstance()->buildLegacyActions(), true);
step('all_routes.yml', fn() => (new RouteManager())->loadAllRoutes()->buildAllRoutes(), true);
step('all_services.yml', fn() => (new ServicesManager())->buildAllServices(), true);
step('version.php', fn() => Builder::buildVersion(), true);

// Étapes non bloquantes pour le filet (documentation API, libs JS réseau).
step('documentation.yml (OpenAPI)', function () {
    $rc = (new RouteManager())->loadAllRoutes()->filterRoutesCollectionByPrefix('api');
    return (new COpenApiManager())->build($rc);
}, false);
step('libs JS', fn() => CLibrary::installAll(), false);

if ($failures > 0) {
    fwrite(STDERR, "\n$failures étape(s) critique(s) en échec.\n");
    exit(1);
}
echo "\nGénération terminée.\n";
