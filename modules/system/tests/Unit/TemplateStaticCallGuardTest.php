<?php

/**
 * @package Hospilibre\System\Tests
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Hospilibre\System\Tests\Unit;

use Ox\Tests\UnitTestHospilibre;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * AUCUN GABARIT NE DOIT APPELER `|static_call` SUR UNE CLASSE ABSENTE SANS GARDE DE MODULE.
 *
 * ⚠ POURQUOI CE TEST EXISTE, ET CE QUE SON ABSENCE A COÛTÉ. Un `static_call` sur une classe absente
 * TUE LA REQUÊTE EN SILENCE : aucune erreur PHP n'est journalisée, `CApp::rip()` n'est pas atteint,
 * et `CApp::checkPeace()` émet « HTTP 500 Application died unexpectedly » SUR UN CORPS DÉJÀ COMPLET.
 * Le navigateur jette donc une réponse valide à cause de son seul statut. Constaté le 2026-10-06 sur
 * l'écran des antécédents : 61 Ko de HTML correct, rejetés, et deux heures de diagnostic — parce
 * qu'aucune trace ne désignait la cause.
 *
 * ET LA DETTE EST UN HÉRITAGE, PAS UN ACCIDENT : ce fork a retiré des modules entiers en laissant
 * leurs appelants. Mesuré le 2026-10-07 : sur 354 `static_call` de gabarits, 57 portent sur une
 * classe qui n'existe plus. Rien n'avertit aujourd'hui qu'un module retiré laisse des appels
 * derrière lui — c'est CE risque, celui des retraits FUTURS, que ce test attrape.
 *
 * CE QU'IL VÉRIFIE : que chaque appel sur une classe absente est enfermé dans un bloc `{{if}}`
 * portant `module_active`. Le court-circuit de `&&` suffirait en théorie, mais seulement si l'appel
 * n'est pas le PREMIER opérande — et c'est l'erreur exacte qui traînait dans trois gabarits.
 *
 * ⚠ ET IL PORTE UNE LISTE BLANCHE EXPLICITE, parce que trois formes de garde légitimes échappent à
 * une analyse statique de gabarits. Les nommer vaut mieux que de les ignorer en silence : une
 * exception écrite se relit, un seuil arrondi ne se relit jamais.
 */
class TemplateStaticCallGuardTest extends UnitTestHospilibre
{
    /**
     * Les occurrences tolérées, avec LA RAISON de chacune.
     *
     * ⚠ CE N'EST PAS UNE LISTE DE PARDON : chaque entrée dit pourquoi l'appel ne peut pas tuer la
     * requête malgré l'absence de garde de module. Si l'une devient fausse, le test doit échouer —
     * c'est pour cela que la clé porte le fichier ET la ligne.
     *
     * @var array<string, string>
     */
    private const TOLERATED = [
        // La garde est portée par une VARIABLE calculée dans le contrôleur :
        // `$use_concentrator = CModule::getActive("patientMonitoring") && CAppUI::conf(...)`
        // (modules/dPsalleOp/httpreq_reveil.php:179). Une analyse de gabarit ne peut pas la voir.
        'modules/dPsalleOp/templates/inc_edit_sortie_salle.tpl' => '$use_concentrator porte déjà CModule::getActive("patientMonitoring")',
        'modules/dPsalleOp/templates/inc_field_timing.tpl'      => '$use_concentrator porte déjà CModule::getActive("patientMonitoring")',
        'modules/dPsalleOp/templates/inc_reveil_reveil.tpl'     => '$use_concentrator porte déjà CModule::getActive("patientMonitoring")',

        // La branche entière est inatteignable : elle teste `$line->_class` contre
        // 'CPrescriptionLineMedicament' et 'CPrescriptionLineMixItem', deux classes ELLES AUSSI
        // absentes — aucun objet ne peut porter ces noms de classe tant que le module manque.
        'modules/soins/templates/timeline/elements/inc_prescription.tpl' => 'branche conditionnée à des classes elles-mêmes absentes',
    ];

    /**
     * ★ LE CLIQUET.
     */
    public function testNoTemplateCallsAnAbsentClassWithoutAModuleGuard(): void
    {
        $findings = [];

        foreach ($this->templates() as $path => $lines) {
            $relative = $this->relative($path);

            if (isset(self::TOLERATED[$relative])) {
                continue;
            }

            foreach ($this->unguardedCalls($lines) as [$line_no, $class]) {
                $findings[] = "{$relative}:{$line_no} → {$class}";
            }
        }

        $this->assertSame(
            [],
            $findings,
            "Un gabarit appelle |static_call sur une classe ABSENTE sans garde de module. Cet appel"
            . " TUE la requête en silence : « HTTP 500 Application died unexpectedly » sur un corps"
            . " déjà complet, sans aucune erreur PHP journalisée.\n\n"
            . "CORRECTIF : enfermer l'appel dans {{if \"<module>\"|module_active}}, ou le placer"
            . " APRÈS un && dont l'opérande gauche est faux sans le module.\n"
            . "⚠ PAS en premier opérande : le court-circuit ne protège alors rien.\n\n"
            . implode("\n", $findings)
        );
    }

    /**
     * ★ LE TÉMOIN : le test doit pouvoir échouer.
     *
     * Un cliquet qui ne peut pas se déclencher ne garde rien, et celui-ci parcourt bientôt quatre
     * mille gabarits — rien dans son résultat vert ne dirait qu'il a seulement mal cherché. Ce test
     * lui soumet donc un gabarit fabriqué qui porte le défaut, et exige qu'il le trouve.
     */
    public function testTheRatchetWouldCatchAnUnguardedCall(): void
    {
        $forged = [
            '<div>',
            "  {{if 'Hospilibre\\Inexistant\\CClasseAbsente::uneMethode'|static_call:null}}",
            '    du contenu',
            '  {{/if}}',
            '</div>',
        ];

        $this->assertSame(
            [[2, 'Hospilibre\Inexistant\CClasseAbsente']],
            $this->unguardedCalls($forged),
            "Le détecteur doit trouver un appel non gardé sur une classe absente. S'il ne le trouve"
            . ' pas, le test principal est vert pour la mauvaise raison.'
        );
    }

    /**
     * ★ ET LE TÉMOIN INVERSE : une garde correcte ne doit PAS être signalée.
     *
     * Sans lui, un détecteur qui signalerait tout satisferait le témoin précédent tout en rendant
     * le test principal inutilisable.
     */
    public function testTheRatchetAcceptsAGuardedCall(): void
    {
        $guarded = [
            "{{if \"dPmedicament\"|module_active}}",
            "  {{if 'Hospilibre\\Inexistant\\CClasseAbsente::uneMethode'|static_call:null}}",
            '    du contenu',
            '  {{/if}}',
            '{{/if}}',
        ];

        $this->assertSame([], $this->unguardedCalls($guarded));

        // Et la garde sur la MÊME condition, après un &&, est valable aussi.
        $inline = [
            "{{if \"dPmedicament\"|module_active && 'Hospilibre\\Inexistant\\CClasseAbsente::uneMethode'|static_call:null}}",
            '{{/if}}',
        ];

        $this->assertSame([], $this->unguardedCalls($inline));
    }

    /**
     * Les appels sur classe absente qui ne sont sous AUCUNE garde de module.
     *
     * ⚠ L'IMBRICATION EST SUIVIE, et il a fallu deux versions pour cela. La première ne regardait
     * que le `{{if}}` le plus proche et signalait `inc_ant_allergie.tpl:105` — dont le `{{if}}`
     * PARENT porte la garde. Un détecteur qui sur-déclare est pire qu'aucun : on l'ignore.
     *
     * @param string[] $lines
     *
     * @return array<array{int, string}>
     */
    private function unguardedCalls(array $lines): array
    {
        $call  = '/[\'"]([A-Za-z_\\\\][\w\\\\]*)::(\w+)[\'"]\s*\|\s*static_call/';
        $stack = [];
        $found = [];

        foreach ($lines as $index => $line) {
            $own = null;
            $pos = max(strrpos($line, '{{if ') === false ? -1 : strrpos($line, '{{if '),
                       strrpos($line, '{{elseif ') === false ? -1 : strrpos($line, '{{elseif '));

            if ($pos >= 0) {
                $own = substr($line, $pos);
            }

            if (preg_match_all($call, $line, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $class = $match[1];

                    if (class_exists($class) || interface_exists($class)) {
                        continue;
                    }

                    $guarded = false;

                    foreach ($stack as $condition) {
                        if (strpos($condition, 'module_active') !== false) {
                            $guarded = true;
                            break;
                        }
                    }

                    // Et sur la condition de la ligne elle-même, la garde doit précéder l'appel :
                    // placée après, elle ne protège rien — le court-circuit joue dans l'autre sens.
                    if (!$guarded && $own !== null) {
                        $at = strpos($own, $class);
                        $guarded = $at !== false
                            && strpos(substr($own, 0, $at), 'module_active') !== false;
                    }

                    if (!$guarded) {
                        $found[] = [$index + 1, $class];
                    }
                }
            }

            // Pile mise à jour APRÈS la ligne : un appel sur la ligne d'ouverture est couvert par
            // sa propre condition, traitée ci-dessus.
            if (preg_match_all('/\{\{if\s|\{\{elseif\s|\{\{\/if\}\}/', $line, $tokens, PREG_OFFSET_CAPTURE)) {
                foreach ($tokens[0] as $token) {
                    [$text, $offset] = $token;

                    if (strpos($text, '{{if') === 0) {
                        $stack[] = substr($line, $offset);
                    } elseif (strpos($text, '{{elseif') === 0) {
                        if ($stack !== []) {
                            array_pop($stack);
                            $stack[] = substr($line, $offset);
                        }
                    } else {
                        array_pop($stack);
                    }
                }
            }
        }

        return $found;
    }

    /**
     * @return array<string, string[]> chemin => lignes
     */
    private function templates(): array
    {
        $root      = $this->root() . '/modules';
        $templates = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (!$file->isFile() || substr($file->getFilename(), -4) !== '.tpl') {
                continue;
            }

            $templates[$file->getPathname()] = explode("\n", (string)file_get_contents($file->getPathname()));
        }

        return $templates;
    }

    private function root(): string
    {
        return dirname(__DIR__, 4);
    }

    private function relative(string $path): string
    {
        return substr($path, strlen($this->root()) + 1);
    }
}
