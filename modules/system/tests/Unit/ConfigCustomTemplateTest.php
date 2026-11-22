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
 * LES GABARITS D'ÉCRAN DE CONFIGURATION DOIVENT EXISTER, ET CEUX QUI EXISTENT DOIVENT SERVIR.
 *
 * ⚠ CE TEST EXISTE PARCE QUE LE FILET PRÉCÉDENT N'A PAS VU LA RÉGRESSION. Le commit `43d5c37b7`
 * — « retrait en masse de 6 fichiers OXOL du module system » — a supprimé deux gabarits d'écran de
 * configuration en laissant les spécifications qui les réclament, DANS DES MODULES PRÉSENTS :
 *
 *   dPcabinet/CConfigurationCabinet.php:107  -> inc_config_category_summary
 *   dPsalleOp/CConfigurationSalleOp.php:51   -> inc_config_category_summary
 *   fhir/CConfigurationFHIR.php:33           -> inc_config_fhir_list_object_delegated
 *
 * Son message annonce pourtant un filet vert : « check_refs : aucune nouvelle référence pendante
 * (1234 connues) ». Le filet comptait les références de CLASSES et de GABARITS — mais une
 * référence écrite en CHAÎNE dans une spécification de configuration (« custom tpl|… ») lui
 * échappait. C'est exactement la limite qu'un contrôle doit nommer : il ne mesurait pas ce qu'on
 * croyait qu'il mesurait.
 *
 * `mb_include` sans `ignore_errors` demande alors à Smarty un fichier qui n'est pas là, sur l'écran
 * de configuration de trois modules installés. `inc_spec_custom.tpl` porte désormais un repli en
 * champ texte, qui préserve la valeur au lieu de la perdre — mais un repli n'est pas une raison de
 * laisser la dette grandir.
 */
class ConfigCustomTemplateTest extends UnitTestHospilibre
{
    /** Où vivent les gabarits d'écran de configuration personnalisés. */
    private const DIRECTORY = 'modules/system/templates/config/custom';

    /**
     * Les gabarits réclamés mais absents que l'on tolère, avec LA RAISON de chacun.
     *
     * ⚠ CE N'EST PAS UNE LISTE DE PARDON MAIS UNE DETTE DATÉE : ces deux-là sont pris en charge par
     * le repli de `inc_spec_custom.tpl`, qui rend la valeur modifiable en clair. Les rétablir
     * demanderait d'écrire une interface spécialisée, ce qui est un travail de conception et non un
     * correctif. Tout NOUVEAU manquant doit faire échouer ce test.
     *
     * @var array<string, string>
     */
    private const TOLERATED_MISSING = [
        'inc_config_category_summary'           => 'supprimé par 43d5c37b7 (retrait OXOL) ; repli en champ texte',
        'inc_config_fhir_list_object_delegated' => 'supprimé par 43d5c37b7 (retrait OXOL) ; repli en champ texte',
    ];

    /**
     * ★ TOUT GABARIT DÉCLARÉ PAR UNE SPÉCIFICATION DOIT EXISTER.
     *
     * C'est le sens qui casse un écran : une spécification qui réclame un fichier absent.
     */
    public function testEveryDeclaredCustomTemplateExists(): void
    {
        $missing = [];

        foreach ($this->declared() as $name => $sources) {
            if (isset(self::TOLERATED_MISSING[$name])) {
                continue;
            }

            if (is_file($this->root() . '/' . self::DIRECTORY . "/{$name}.tpl")) {
                continue;
            }

            $missing[] = "{$name} — réclamé par " . implode(', ', $sources);
        }

        $this->assertSame(
            [],
            $missing,
            "Une spécification de configuration réclame un gabarit qui n'existe pas. L'écran de"
            . " configuration du module concerné demandera à Smarty un fichier absent.\n"
            . "⚠ Si c'est la conséquence d'un retrait de module, le retrait a laissé ses APPELANTS"
            . " — même défaut que les |static_call sur classe absente.\n\n"
            . implode("\n", $missing)
        );
    }

    /**
     * ★ LE TÉMOIN : le test doit pouvoir échouer.
     *
     * Il repose sur une extraction par expression régulière dans des fichiers PHP ; rien dans un
     * résultat vert ne dirait qu'elle n'a simplement rien trouvé. On vérifie donc qu'elle trouve
     * bien les déclarations réelles, et en nombre.
     */
    public function testTheExtractionActuallyFindsDeclarations(): void
    {
        $declared = $this->declared();

        $this->assertGreaterThan(
            10,
            count($declared),
            "L'extraction doit trouver les déclarations « custom tpl|… » du dépôt. Si elle n'en"
            . ' trouve presque aucune, le test précédent est vert pour avoir mal cherché.'
        );

        // Et elle doit trouver NOMMÉMENT les deux manquants connus, sans quoi la liste blanche
        // ci-dessus masquerait une extraction défaillante.
        foreach (array_keys(self::TOLERATED_MISSING) as $known) {
            $this->assertArrayHasKey(
                $known,
                $declared,
                "L'extraction doit retrouver « {$known} », qui est réclamé par une spécification."
                . ' Son absence signifierait que le motif ne reconnaît plus ces déclarations.'
            );
        }
    }

    /**
     * Les gabarits déclarés par une spécification, et où.
     *
     * @return array<string, string[]>
     */
    private function declared(): array
    {
        $declared = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root() . '/modules'));

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string)file_get_contents($file->getPathname());

            if (!preg_match_all('/tpl\|(inc_config_[a-z0-9_]+)/i', $source, $matches)) {
                continue;
            }

            foreach (array_unique($matches[1]) as $name) {
                $declared[$name][] = substr($file->getPathname(), strlen($this->root()) + 1);
            }
        }

        return $declared;
    }

    private function root(): string
    {
        return dirname(__DIR__, 4);
    }
}
