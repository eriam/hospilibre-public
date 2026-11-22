<?php

/**
 * check_refs.php — Détecteur de références de classes pendantes (HospiLibre, Phase 1).
 *
 * Le risque dominant d'un refactor de suppression n'est pas le bug de logique mais la
 * RÉFÉRENCE PENDANTE : un fichier CONSERVÉ qui importe (use) / étend (extends) / implémente
 * (implements) une classe RETIRÉE. Cet outil parcourt le code source, extrait chaque
 * référence de classe explicite et vérifie qu'elle se résout, via :
 *   - le classmap Hospilibre généré (includes/classmap.php) pour les classes applicatives ;
 *   - l'autoloader Composer (PSR-4/classmap, findFile() — sans charger la classe) ;
 *   - les classes/interfaces/traits internes de PHP.
 *
 * Usage :
 *   php tools/check_refs.php [--json=fichier.json] [--baseline=fichier.json] [chemins...]
 *
 * Sans --baseline : liste toutes les références non résolues (sortie 1 si > 0).
 * Avec --baseline  : ne signale que les NOUVELLES références non résolues vs la baseline
 *                    (sortie 1 si régression). Mode utilisé en Phase 2.
 *
 * Couverture : use / extends / implements (références statiques explicites). Les références
 * dynamiques (noms de classe en chaîne, `new $var`) ne sont volontairement pas couvertes.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$root = dirname(__DIR__);

// --- Arguments -----------------------------------------------------------
$json_out = null;
$baseline = null;
$paths    = [];
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--json=') === 0) {
        $json_out = substr($arg, 7);
    } elseif (strpos($arg, '--baseline=') === 0) {
        $baseline = substr($arg, 11);
    } else {
        $paths[] = $arg;
    }
}
if (!$paths) {
    $paths = ['core', 'cli', 'modules', 'includes', 'installation', 'config', 'index.php'];
}

// --- Autoload + classmap applicatif -------------------------------------
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "ERREUR : vendor/autoload.php absent. Lancer composer install d'abord.\n");
    exit(2);
}
/** @var Composer\Autoload\ClassLoader $loader */
$loader = require $autoload;

$classmap_file = $root . '/includes/classmap.php';
if (!is_file($classmap_file)) {
    fwrite(STDERR, "ERREUR : includes/classmap.php absent. Générer via tools/build_generated.php.\n");
    exit(2);
}
$app_classes = [];
foreach (array_keys(include $classmap_file) as $fqcn) {
    $app_classes[ltrim($fqcn, '\\')] = true;
}

// --- Collecte des fichiers PHP ------------------------------------------
$skip = ['vendor', 'node_modules', 'tmp', 'cache', '.git'];
$files = [];
foreach ($paths as $p) {
    $abs = $p[0] === '/' ? $p : $root . '/' . $p;
    if (is_file($abs)) {
        if (substr($abs, -4) === '.php') {
            $files[] = $abs;
        }
        continue;
    }
    if (!is_dir($abs)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS),
            fn($c) => $c->isDir() ? !in_array($c->getFilename(), $skip, true) : substr($c->getFilename(), -4) === '.php'
        )
    );
    foreach ($it as $f) {
        $files[] = $f->getPathname();
    }
}
sort($files);

// --- Mots-clés à ne jamais résoudre comme des classes -------------------
$KEYWORDS = array_flip([
    'self', 'static', 'parent', 'int', 'float', 'string', 'bool', 'array', 'object',
    'callable', 'iterable', 'void', 'mixed', 'null', 'false', 'true', 'never', 'this',
]);

// --- Résolution (mémoïsée) ----------------------------------------------
function is_resolvable(string $fqcn, array $app_classes, $loader): bool
{
    static $cache = [];
    $fqcn = ltrim($fqcn, '\\');
    if ($fqcn === '') {
        return true;
    }
    if (isset($cache[$fqcn])) {
        return $cache[$fqcn];
    }
    $ok = isset($app_classes[$fqcn])
        || $loader->findFile($fqcn) !== false
        || class_exists($fqcn, false)
        || interface_exists($fqcn, false)
        || trait_exists($fqcn, false);
    return $cache[$fqcn] = $ok;
}

/** Résout un nom (éventuellement court) en FQCN via les imports + le namespace courant. */
function resolve_name(string $name, string $namespace, array $uses): string
{
    $name = trim($name);
    if ($name === '' || $name[0] === '\\') {
        return ltrim($name, '\\');
    }
    $parts = explode('\\', $name);
    $first = strtolower($parts[0]);
    if (isset($uses[$first])) {
        $rest = array_slice($parts, 1);
        return $uses[$first] . ($rest ? '\\' . implode('\\', $rest) : '');
    }
    return $namespace !== '' ? $namespace . '\\' . $name : $name;
}

// --- Analyse d'un fichier (regex, robuste & rapide) ---------------------
function analyze_file(string $src): array
{
    $namespace = '';
    if (preg_match('/^\s*namespace\s+([^\s;{]+)/m', $src, $m)) {
        $namespace = trim($m[1], '\\');
    }

    // Imports `use` (hors function/const/closure). Cible = FQCN, déjà pleinement qualifié.
    $uses = [];   // alias_minuscule => FQCN
    $refs = [];   // noms à résoudre
    if (preg_match_all('/^[ \t]*use\s+(?!function\b|const\b)([^;(]+);/mi', $src, $mm)) {
        foreach ($mm[1] as $decl) {
            foreach (expand_use($decl) as [$fqcn, $alias]) {
                // N'alimente la map d'alias qu'avec les cibles QUALIFIÉES (vrais imports).
                // Un `use Trait;` non qualifié (trait dans le corps de classe) ne doit pas
                // s'auto-référencer : on le résout via le namespace courant comme un extends.
                if (strpos($fqcn, '\\') !== false) {
                    $uses[strtolower($alias)] = $fqcn;
                }
                $refs[] = $fqcn;
            }
        }
    }

    // extends (1 nom) — classes & interfaces
    if (preg_match_all('/\bextends\s+([\\\\A-Za-z0-9_]+)/', $src, $mm)) {
        foreach ($mm[1] as $n) {
            $refs[] = $n;
        }
    }
    // implements (liste) — jusqu'à l'accolade ouvrante
    if (preg_match_all('/\bimplements\s+([\\\\A-Za-z0-9_,\s]+?)\s*\{/', $src, $mm)) {
        foreach ($mm[1] as $list) {
            foreach (explode(',', $list) as $n) {
                $n = trim($n);
                if ($n !== '') {
                    $refs[] = $n;
                }
            }
        }
    }

    return [$namespace, $uses, $refs];
}

/** Développe une déclaration `use` en paires [FQCN, alias]. Gère "as" et le groupage {A,B}. */
function expand_use(string $decl): array
{
    $decl = trim($decl);
    $out  = [];
    if (strpos($decl, '{') !== false) {
        if (!preg_match('/^(.*?)\{(.*)\}/s', $decl, $m)) {
            return $out;
        }
        $prefix = $m[1];
        foreach (explode(',', $m[2]) as $part) {
            if (trim($part) !== '') {
                $out[] = split_alias($prefix . trim($part));
            }
        }
        return $out;
    }
    foreach (explode(',', $decl) as $part) {
        if (trim($part) !== '') {
            $out[] = split_alias($part);
        }
    }
    return $out;
}

function split_alias(string $s): array
{
    $s = trim($s);
    if (preg_match('/^(.*?)\s+as\s+(\S+)$/is', $s, $m)) {
        $fqcn = ltrim(trim($m[1]), '\\');
        $alias = trim($m[2]);
    } else {
        $fqcn = ltrim($s, '\\');
        $parts = explode('\\', $fqcn);
        $alias = end($parts);
    }
    return [$fqcn, $alias];
}

// --- Boucle principale ---------------------------------------------------
$unresolved = [];
foreach ($files as $file) {
    $src = file_get_contents($file);
    if ($src === false || strpos($src, '<?') === false) {
        continue;
    }
    [$namespace, $uses, $refs] = analyze_file($src);
    $rel = ltrim(str_replace($root, '', $file), '/');
    foreach ($refs as $ref) {
        $base = strtolower(trim($ref, '\\'));
        if (isset($KEYWORDS[$base]) || $base === '') {
            continue;
        }
        // Cible de `use` = déjà FQCN ; extends/implements = à résoudre. On teste les deux.
        $ok = is_resolvable(ltrim($ref, '\\'), $app_classes, $loader)
            || is_resolvable(resolve_name($ref, $namespace, $uses), $app_classes, $loader);
        if (!$ok) {
            $unresolved[] = $rel . '::' . ltrim($ref, '\\');
        }
    }
}
$unresolved = array_values(array_unique($unresolved));
sort($unresolved);

// --- Sortie / baseline ---------------------------------------------------
if ($json_out) {
    file_put_contents($json_out, json_encode($unresolved, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

if ($baseline) {
    $base = is_file($baseline) ? json_decode(file_get_contents($baseline), true) : [];
    $base = is_array($base) ? array_flip($base) : [];
    $new  = array_values(array_filter($unresolved, fn($u) => !isset($base[$u])));
    if ($new) {
        fwrite(STDERR, "❌ RÉGRESSION : " . count($new) . " nouvelle(s) référence(s) pendante(s) vs baseline :\n");
        foreach ($new as $u) {
            fwrite(STDERR, "   $u\n");
        }
        exit(1);
    }
    echo "✅ Aucune nouvelle référence pendante vs baseline (" . count($unresolved) . " connues).\n";
    exit(0);
}

echo "Fichiers analysés : " . count($files) . "\n";
echo "Références non résolues : " . count($unresolved) . "\n";
foreach ($unresolved as $u) {
    echo "  $u\n";
}
exit($unresolved ? 1 : 0);
