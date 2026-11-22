import { defineConfig, devices } from '@playwright/test';
import path from 'path';

/**
 * Suite E2E Playwright — vérification UI du balayage clean-room OXOL.
 *
 * Cible par défaut : l'app servie par le harnais local (tools/harness, apache
 * sur 127.0.0.1:8000, arbre de travail COURANT) → vérif AVANT deploy.
 * Surchargeable : HOSPI_BASE_URL=http://staging.example pour tester un staging.
 *
 * Lancer :  npx playwright test -c tools/e2e/playwright.config.ts
 */
const AUTH_STATE = path.join(__dirname, '.auth', 'state.json');

export default defineConfig({
  testDir: path.join(__dirname, 'tests'),
  outputDir: path.join(__dirname, '.results'),
  timeout: 60_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list'], ['html', { outputFolder: path.join(__dirname, 'report'), open: 'never' }]],
  use: {
    baseURL: process.env.HOSPI_BASE_URL || 'http://127.0.0.1:8000',
    ignoreHTTPSErrors: true,
    actionTimeout: 15_000,
    navigationTimeout: 30_000,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
    video: 'off',
  },
  projects: [
    { name: 'setup', testMatch: /auth\.setup\.ts/ },
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'], storageState: AUTH_STATE },
      dependencies: ['setup'],
    },
  ],
});
