import { Page, expect } from '@playwright/test';

/**
 * Charge une URL et échoue si l'app a renvoyé une 500 legacy ou une erreur PHP
 * visible dans le corps (Application died / Fatal / classe manquante / Smarty).
 * Retourne le statut HTTP pour des assertions complémentaires.
 */
export async function gotoNoError(page: Page, url: string): Promise<number> {
  const resp = await page.goto(url, { waitUntil: 'domcontentloaded' });
  const status = resp?.status() ?? 0;

  expect(status, `HTTP ${status} sur ${url}`).toBeLessThan(500);

  const body = await page.content();
  const patterns: [RegExp | string, string][] = [
    ['Application died unexpectedly', 'CApp a avorté'],
    [/Fatal error/i, 'Fatal error PHP'],
    [/Uncaught (Error|Exception|TypeError)/, 'Exception PHP non attrapée'],
    [/Parse error/i, 'Parse error PHP'],
    [/Class ["'][\w\\]+["'] not found/, 'Classe introuvable'],
    [/Smarty(Compiler)?Exception|Syntax error in template/i, 'Erreur Smarty'],
  ];
  for (const [pat, label] of patterns) {
    expect(body, `${label} sur ${url}`).not.toMatch(pat as RegExp);
  }
  return status;
}
