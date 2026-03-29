import { defineConfig, devices } from '@playwright/test'

// Nuxt app uses app.baseURL `/admin/` — tests use paths relative to this origin.
const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:3333/admin'

/** Dedicated PHP port for e2e so local dev servers on :8081 do not block Playwright's php -S. */
const phpPort = process.env.PLAYWRIGHT_PHP_PORT ?? '18081'
const phpOrigin =
  process.env.NUXT_PUBLIC_PHP_ORIGIN ?? `http://127.0.0.1:${phpPort}`

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
      `export NUXT_PUBLIC_PHP_ORIGIN=${phpOrigin} && PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:${phpPort} -t ../../public ../../public/router.php & npm run dev`,
    )}`,
    url: 'http://localhost:3333/admin',
    reuseExistingServer: !process.env.CI,
    timeout: 120 * 1000,
    env: {
      ...process.env,
      NUXT_PUBLIC_PHP_ORIGIN: phpOrigin,
    },
  },
})
