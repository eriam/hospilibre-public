<?php

/**
 * inventory.php — Inventaire des capacités de l'application (HospiLibre, Phase 1).
 *
 * Produit un JSON DÉTERMINISTE décrivant ce que l'application expose : modules, classes
 * modèle (objets stockables → tables), actions legacy, routes, types de ressources API.
 * C'est l'ORACLE de non-régression fonctionnelle : en Phase 2, le diff de cet inventaire
 * avant/après une suppression doit être EXACTEMENT égal aux suppressions voulues. Toute
 * capacité disparue hors liste = régression accidentelle.
 *
 * Usage : php tools/inventory.php [--json=fichier.json]
 * Sans --json : écrit sur stdout.
 *
 * Prérequis : vendor/ + includes/classmap.php (composer install + tools/build_generated.php).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_STRICT);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use Ox\Core\Cache;
use Ox\Core\CClassMap;
use Ox\Core\CModelObject;
use Ox\Core\CStoredObject;
use Ox\Core\Composer\CComposerScript;

// Contexte de build : neutralise getBackProps() + tolère les sources de données externes
// non configurées (cf. tools/build_generated.php pour le détail).
CComposerScript::$is_running = true;
set_error_handler(static fn(int $no, string $str): bool => strpos($str, 'DSN type') !== false, E_ALL);

$json_out = null;
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--json=') === 0) {
        $json_out = substr($arg, 7);
    }
}

Cache::init($root);
$cm = CClassMap::getInstance();

// --- Modules : déduits des namespaces Hospilibre\<Module> du classmap ----
$modules = [];
foreach (array_keys($cm->getClassMap()) as $fqcn) {
    if (preg_match('/^Ox\\\\Hospilibre\\\\([^\\\\]+)\\\\/', $fqcn, $m)) {
        $modules[$m[1]] = true;
    }
}
$modules = array_keys($modules);
sort($modules);

// --- Classes modèle (CModelObject) et objets stockables (→ table) ----------
$model_classes = [];
$tables        = [];
try {
    $children = $cm->getClassMap(CModelObject::class)->children;
} catch (\Throwable $e) {
    $children = [];
}
$children[] = CModelObject::class;
sort($children);
foreach ($children as $fqcn) {
    $model_classes[] = $fqcn;
    try {
        $rc = new ReflectionClass($fqcn);
        if (!$rc->isInstantiable() || !$rc->isSubclassOf(CStoredObject::class)) {
            continue;
        }
        $instance = $rc->isFinal() ? new $fqcn() : $rc->newInstanceWithoutConstructor();
        $instance->_class = $rc->getShortName();
        $spec = $instance->getSpec();
        if (!empty($spec->table)) {
            $tables[$spec->table] = $rc->getShortName();
        }
    } catch (\Throwable $e) {
        // modèle non instanciable proprement hors runtime complet : ignoré
    }
}
ksort($tables);

// --- Actions legacy (includes/legacy_actions.php) --------------------------
$legacy_actions = [];
$la_file = $root . '/includes/legacy_actions.php';
if (is_file($la_file)) {
    $la = include $la_file;
    if (is_array($la)) {
        // structure : [controller => [action => ...]] ou liste plate selon version
        foreach ($la as $k => $v) {
            if (is_array($v)) {
                foreach (array_keys($v) as $action) {
                    $legacy_actions[] = "$k::$action";
                }
            } else {
                $legacy_actions[] = (string)$k;
            }
        }
    }
}
sort($legacy_actions);

// --- Routes (includes/all_routes.yml) --------------------------------------
$routes = [];
$routes_file = $root . '/includes/all_routes.yml';
if (is_file($routes_file)) {
    foreach (file($routes_file) as $line) {
        // noms de route = clés de 1er niveau (non indentées, finissant par ':')
        if (preg_match('/^([A-Za-z0-9_.]+):\s*$/', $line, $m)) {
            $routes[] = $m[1];
        }
    }
}
sort($routes);
$routes = array_values(array_unique($routes));

// --- Types de ressources API (constante RESOURCE_TYPE des modèles) ---------
$resource_types = [];
foreach ($children as $fqcn) {
    try {
        $rc = new ReflectionClass($fqcn);
        if (!$rc->isInstantiable()) {
            continue;
        }
        $rt = $fqcn::RESOURCE_TYPE;
        if ($rt && $rt !== CModelObject::RESOURCE_TYPE) {
            $resource_types[$rt] = true;
        }
    } catch (\Throwable $e) {
    }
}
$resource_types = array_keys($resource_types);
sort($resource_types);

// --- Synthèse --------------------------------------------------------------
$inventory = [
    'meta' => [
        'total_classes'  => count($cm->getClassMap()),
        'generated_with' => 'tools/inventory.php',
    ],
    'counts' => [
        'modules'        => count($modules),
        'model_classes'  => count($model_classes),
        'tables'         => count($tables),
        'legacy_actions' => count($legacy_actions),
        'routes'         => count($routes),
        'resource_types' => count($resource_types),
    ],
    'modules'        => $modules,
    'model_classes'  => $model_classes,
    'tables'         => $tables,
    'legacy_actions' => $legacy_actions,
    'routes'         => $routes,
    'resource_types' => $resource_types,
];

$out = json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
if ($json_out) {
    file_put_contents($json_out, $out);
    fwrite(STDERR, "Inventaire écrit : $json_out\n");
    foreach ($inventory['counts'] as $k => $v) {
        fwrite(STDERR, sprintf("  %-16s %d\n", $k, $v));
    }
} else {
    echo $out;
}
