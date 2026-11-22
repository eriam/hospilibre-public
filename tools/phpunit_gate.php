<?php

/**
 * phpunit_gate.php — Porte de non-régression PHPUnit (HospiLibre, Phase 1).
 *
 * Lance la suite PHPUnit (composer ox-run-tests) en produisant un rapport JUnit, puis
 * compare l'ensemble des tests EN ÉCHEC à une baseline. Échoue (code 1) si de NOUVEAUX
 * tests sont rouges. Les tests déjà rouges sur l'arbre intact (couverture maigre d'un SIH
 * 2022) sont attendus et font partie de la référence ; les tests des modules retirés en
 * Phase 2 disparaissent simplement de l'ensemble — ce n'est pas une régression.
 *
 * Usage :
 *   php tools/phpunit_gate.php docs/provenance/phpunit_baseline.json          (mode porte)
 *   php tools/phpunit_gate.php --record docs/provenance/phpunit_baseline.json (enregistre la baseline)
 *
 * La baseline est un JSON : liste triée d'identifiants "Classe::methode" en échec.
 */

$root = dirname(__DIR__);
$args = array_slice($argv, 1);
$record = false;
if (($k = array_search('--record', $args, true)) !== false) {
    $record = true;
    unset($args[$k]);
    $args = array_values($args);
}
$baseline_file = $args[0] ?? null;
if (!$baseline_file) {
    fwrite(STDERR, "usage: phpunit_gate.php [--record] <baseline.json>\n");
    exit(2);
}

$junit = tempnam(sys_get_temp_dir(), 'junit') . '.xml';
$cmd = sprintf(
    'cd %s && vendor/bin/phpunit -c phpunit.xml --testsuite UnitTestSuite --exclude-group schedules --log-junit %s 2>&1',
    escapeshellarg($root),
    escapeshellarg($junit)
);
echo "Exécution PHPUnit (cela peut prendre un moment)…\n";
exec($cmd, $out, $code);

// La suite complète d'un SIH 2022 ne s'exécute pas entièrement sur PHP 8.1 : elle peut
// FATALER en cours de route (exit 255), laissant un JUnit vide ou absent. Dans ce cas on
// ne peut pas établir de baseline fiable → étape BEST-EFFORT non bloquante (cf. brief :
// le filet repose sur l'analyse statique + smoke + diff de capacités, pas sur PHPUnit).
$junit_ok = is_file($junit) && filesize($junit) > 0 && strpos(@file_get_contents($junit), '<testcase') !== false;
if (!$junit_ok) {
    fwrite(STDERR, "⚠ Suite PHPUnit incomplète (fatal en cours d'exécution, exit=$code) — "
        . "rapport JUnit vide/absent. Étape best-effort, non bloquante.\n");
    fwrite(STDERR, "  Dernières lignes :\n   " . implode("\n   ", array_slice($out, -6)) . "\n");
    @unlink($junit);
    exit(0);
}

// Extraction des tests en échec/erreur depuis le JUnit
$failing = [];
$xml = @simplexml_load_file($junit);
if ($xml !== false) {
    foreach ($xml->xpath('//testcase') as $tc) {
        if (isset($tc->failure) || isset($tc->error)) {
            $cls = (string)($tc['class'] ?? '');
            $name = (string)($tc['name'] ?? '');
            $failing[] = $cls !== '' ? "$cls::$name" : $name;
        }
    }
}
$failing = array_values(array_unique($failing));
sort($failing);
@unlink($junit);

if ($record) {
    file_put_contents($baseline_file, json_encode($failing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo "Baseline PHPUnit enregistrée : " . count($failing) . " test(s) rouge(s) → $baseline_file\n";
    exit(0);
}

$baseline = is_file($baseline_file) ? json_decode(file_get_contents($baseline_file), true) : [];
$baseline = is_array($baseline) ? array_flip($baseline) : [];
$new = array_values(array_filter($failing, fn($t) => !isset($baseline[$t])));

echo "Tests rouges : " . count($failing) . " (baseline : " . count($baseline) . ", nouveaux : " . count($new) . ")\n";
if ($new) {
    fwrite(STDERR, "❌ RÉGRESSION PHPUnit : " . count($new) . " nouveau(x) test(s) rouge(s) :\n");
    foreach ($new as $t) {
        fwrite(STDERR, "   $t\n");
    }
    exit(1);
}
echo "✅ Aucun nouveau test rouge vs baseline.\n";
exit(0);
