import { defineConfig, devices } from '@playwright/test'
import { CLAUDRIEL_DEV_ADMIN_PORT, CLAUDRIEL_DEV_PHP_PORT } from './devPorts'

// Nuxt app uses app.baseURL `/admin/` — tests use paths relative to this origin.
const baseURL =
  process.env.PLAYWRIGHT_BASE_URL ?? `http://localhost:${CLAUDRIEL_DEV_ADMIN_PORT}/admin`

/** Same PHP port as local dev (`devPorts.ts`); override only for parallel runs. */
const phpPort = process.env.PLAYWRIGHT_PHP_PORT ?? String(CLAUDRIEL_DEV_PHP_PORT)
const phpOrigin =
  process.env.NUXT_PUBLIC_PHP_ORIGIN ?? `http://localhost:${phpPort}`

export default defineConfig({
  testDir: './e2e',
  // Agent-backed chat UI stays disabled without Docker sidecar + keys; skip in CI smoke.
  testIgnore: process.env.CI ? ['**/claudriel-chat-continue.spec.ts'] : [],
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  reporter: process.env.CI ? 'line' : 'html',
  use: {
    baseURL,
    trace: 'on-first-retry',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  webServer: {
    // From frontend/admin: PHP (router.php) + Nuxt dev; proxy targets phpOrigin for /api, /graphql, etc.
    // Build argv for sh -c with JSON.stringify so ${phpPort}/${phpOrigin} are never mistaken for shell vars
    // (values are fixed in Node here; the shell must not re-parse unquoted ${...}).
    command: `sh -c ${JSON.stringify(
      `export NUXT_PUBLIC_PHP_ORIGIN=${phpOrigin} && PHP_CLI_SERVER_WORKERS=4 php -S localhost:${phpPort} -t ../../public ../../public/router.php & npm run dev`,
    )}`,
    url: `http://localhost:${CLAUDRIEL_DEV_ADMIN_PORT}/admin`,
    reuseExistingServer: !process.env.CI,
    timeout: 120 * 1000,
    env: {
      ...process.env,
      NUXT_PUBLIC_PHP_ORIGIN: phpOrigin,
    },
  },
})
