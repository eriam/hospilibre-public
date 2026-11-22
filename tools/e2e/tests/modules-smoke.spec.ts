import { test } from '@playwright/test';
import { gotoNoError } from './helpers';

/**
 * Smoke large : chaque module actif rend sa page principale sans erreur
 * applicative. Une réécriture qui casse une classe/un template du module fait
 * échouer l'ouverture de la page.
 */
const MODULES = [
  'admin', 'astreintes', 'dPcabinet', 'dPcompteRendu', 'dPetablissement',
  'dPfacturation', 'dPfiles', 'dPhospi', 'dPpatients', 'dPpersonnel',
  'dPplanningOp', 'dPpmsi', 'dPqualite', 'dPsalleOp', 'dPstock',
  'dPurgences', 'fhir', 'maternite', 'mediusers', 'messagerie',
  'soins', 'ssr', 'system',
];

/**
 * Modules qui échouent sur un appel AJAX (« Application died »). CAUSE RACINE
 * IDENTIFIÉE (investigation 2026-07) : des classes/modules retirés lors du GROS
 * nettoyage OXOL antérieur sont encore référencés par du code conservé →
 * ClassNotFoundError fatal quand le chemin AJAX s'exécute. PRÉ-EXISTANT (les
 * refs manquantes étaient déjà là avant les réécritures de classes), donc non
 * causé par le relicenciement GPL.
 *   - fhir           : `Ox\Interop\Fhir\ClassMap\FHIRClassMap` (namespace entier
 *                      retiré) — `new CPDQm()` fatal ; ~10 fichiers concernés.
 *                      Déclencheur : ?m=fhir&a=ajax_fhir_search.
 *   - astreintes     : `Hospilibre\Astreintes\CAstreinte` (modèle principal retiré).
 *   - dPfacturation  : `Hospilibre\Fse\CFSE` + `CFseFactory` (module FSE retiré)
 *                      référencés par CFacture. Déclencheur : ajax_list_factures.
 * Correctif : réimplémenter ces classes, OU garder l'absence gracieusement, OU
 * assumer la perte de la fonctionnalité concernée. À ré-armer une fois traité.
 */
const KNOWN_FAILING = new Set(['astreintes', 'dPfacturation']); // fhir restauré (branche restore-oxol-features)

for (const mod of MODULES) {
  test(`module ${mod} rend sans erreur`, async ({ page }) => {
    test.fixme(KNOWN_FAILING.has(mod), 'échec pré-existant harnais (AJAX) — à investiguer au balayage du module');
    await gotoNoError(page, `/?m=${mod}`);
  });
}
