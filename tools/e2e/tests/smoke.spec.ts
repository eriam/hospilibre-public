import { test, expect, Page } from '@playwright/test';

/**
 * Fumée : l'app authentifiée répond sans "Application died unexpectedly" et
 * rend les briques d'interface principales.
 */

/** Échoue si la page a renvoyé une 500 legacy ou une erreur PHP visible. */
async function assertNoAppError(page: Page, url: string) {
  const resp = await page.goto(url, { waitUntil: 'domcontentloaded' });
  const status = resp?.status() ?? 0;
  expect(status, `HTTP status pour ${url}`).toBeLessThan(500);

  const body = await page.content();
  expect(body, `Erreur applicative sur ${url}`).not.toContain('Application died unexpectedly');
  expect(body, `Fatal PHP sur ${url}`).not.toMatch(/Fatal error|Uncaught (Error|Exception)|Parse error/);
  expect(body, `Classe manquante sur ${url}`).not.toMatch(/Class ["'][\w\\]+["'] not found/);
}

test('accueil authentifié rend sans erreur', async ({ page }) => {
  await assertNoAppError(page, '/');
  // Une session authentifiée n'affiche plus le formulaire de connexion.
  await expect(page.locator('form[name="loginFrm"]')).toHaveCount(0);
});

test('module patients accessible', async ({ page }) => {
  await assertNoAppError(page, '/?m=dPpatients');
});

test('module system (configuration) accessible', async ({ page }) => {
  await assertNoAppError(page, '/?m=system&a=configure');
});
