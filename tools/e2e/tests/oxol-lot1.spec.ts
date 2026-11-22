import { test } from '@playwright/test';
import { gotoNoError } from './helpers';

/**
 * Pages exerçant les templates OXOL réécrits (lot 1). Chaque entrée charge une
 * page dont le rendu inclut un template réécrit ; un markup Smarty cassé y
 * ferait apparaître une erreur (capturée par gotoNoError).
 *
 * À COMPLÉTER au fil du balayage : ajouter ici la page de chaque template/classe
 * réécrit pour garder la couverture UI à jour.
 */
const PAGES: { name: string; url: string }[] = [
  { name: 'config dPstock (inc_configure_tools)', url: '/?m=dPstock&a=configure' },
  { name: 'config dPcim10 (inc_outils_cim10)', url: '/?m=dPcim10&a=configure' },
  { name: 'config dPsalleOp (inc_vw_tools)', url: '/?m=dPsalleOp&a=configure' },
  { name: 'config api (inc_config_api)', url: '/?m=api&a=configure' },
  { name: 'config eai (message_supported_form)', url: '/?m=eai&a=configure' },
  { name: 'patients index (lieu_naissance / status)', url: '/?m=dPpatients&a=vw_idx_patients' },
];

for (const p of PAGES) {
  test(`OXOL lot1 — ${p.name}`, async ({ page }) => {
    await gotoNoError(page, p.url);
  });
}
