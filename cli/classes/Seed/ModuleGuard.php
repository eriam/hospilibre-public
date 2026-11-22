<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

use Ox\Core\Module\CModule;

/**
 * Refuse de seeder si un module dont le seed dépend n'est pas actif.
 *
 * Ce garde-fou n'est pas de la prudence de principe, il répond à un mode de panne SILENCIEUX.
 * `CStoredObject` déduit le module d'un modèle de son chemin de fichier, et un modèle dont le
 * module est inactif a `_ref_module === null` : `loadFwdRef()` rend alors `null` et `delete()`
 * abandonne ses références arrière, SANS erreur. Un seed lancé dans ces conditions produit un
 * graphe troué qui a toutes les apparences du succès — un praticien sans fonction, un séjour
 * dont le praticien ne se charge pas — et le formateur ne le découvre qu'en ouvrant l'écran.
 *
 * Constaté en vrai : sur l'instance de vérification, `mediusers` était inactif faute d'avoir
 * fini son setup, et `CSejour::praticien_id` comme `COperation::chir_id` pointent `CMediusers`.
 */
final class ModuleGuard
{
    /**
     * Modules indispensables à l'anneau « noyau hospitalisation », avec ce qu'ils portent.
     */
    public const CORE_MODULES = [
        'dPetablissement' => 'établissement et services (CGroups)',
        'mediusers'       => 'praticiens et fonctions (CMediusers, CFunctions)',
        'dPhospi'         => 'services, chambres, lits, affectations',
        'dPbloc'          => 'bloc opératoire, salles, plages',
        'dPpatients'      => 'patients',
        'dPplanningOp'    => 'séjours et interventions',
        'dPsante400'      => 'identifiants permanents des patients (IPP, CIdSante400)',
        'system'          => 'traçabilité du seed (CFixturesReference)',
    ];

    /**
     * @param string[]|null $modules Modules à vérifier ; par défaut, le noyau.
     *
     * @throws SeedException
     */
    public static function assertActive(?array $modules = null): void
    {
        $required = $modules ?? array_keys(self::CORE_MODULES);

        $missing = [];
        foreach ($required as $module) {
            if (!CModule::getActive($module)) {
                $reason           = self::CORE_MODULES[$module] ?? 'dépendance du seed';
                $missing[]        = "{$module} ({$reason})";
            }
        }

        if ($missing === []) {
            return;
        }

        throw new SeedException(
            "Module(s) inactif(s), seed impossible :\n  - " . implode("\n  - ", $missing)
            . "\nUn modèle dont le module est inactif échoue SANS erreur (_ref_module null) :"
            . " le seed produirait un graphe silencieusement incomplet."
            . "\nVérifier avec « bin/console ox-modules:setup » puis relancer."
        );
    }

    /**
     * Version non levante, pour un diagnostic.
     *
     * @return string[] modules manquants
     */
    public static function inactiveModules(?array $modules = null): array
    {
        $required = $modules ?? array_keys(self::CORE_MODULES);

        $missing = [];
        foreach ($required as $module) {
            if (!CModule::getActive($module)) {
                $missing[] = $module;
            }
        }

        return $missing;
    }
}
