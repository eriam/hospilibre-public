import { test as setup, expect } from '@playwright/test';
import path from 'path';

// Ce fichier vit dans tests/ ; l'état est partagé avec playwright.config.ts un
// niveau au-dessus (tools/e2e/.auth/state.json).
const AUTH_STATE = path.join(__dirname, '..', '.auth', 'state.json');

const USER = process.env.HOSPI_USER || 'admin';
const PASS = process.env.HOSPI_PASS;
if (!PASS) throw new Error('HOSPI_PASS non défini');

/**
 * Authentifie une fois et sauvegarde l'état de session (cookies) réutilisé par
 * tous les tests. Le formulaire legacy est `loginFrm` (champs username/password ;
 * les champs _login/_pwd sont des leurres anti-bot, laissés vides).
 */
setup('authenticate', async ({ page }) => {
  await page.goto('/');

  // Plusieurs formulaires portent des champs username/password (sessionLock,
  // userSwitch, loginFrm) : on cible explicitement le formulaire de connexion.
  const form = page.locator('form[name="loginFrm"]');
  await form.locator('input[name="username"]').fill(USER);
  await form.locator('input[name="password"]').fill(PASS);

  // Soumission puis attente de la page authentifiée.
  await Promise.all([
    page.waitForLoadState('networkidle').catch(() => {}),
    form.locator('input[name="password"]').press('Enter'),
  ]);

  // Après login réussi, le champ de mot de passe n'est plus présent (on n'est
  // plus sur la page de connexion).
  await expect(page.locator('form[name="loginFrm"]')).toHaveCount(0, { timeout: 30_000 });

  await page.context().storageState({ path: AUTH_STATE });
});
