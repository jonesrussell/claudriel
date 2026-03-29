/**
 * Minimal E2E: mocked session + one stable route. Run by default (`npm run test:e2e`).
 * Full UI coverage lives in sibling specs — use `npm run test:e2e:all` when the stack is stable.
 */
import { test, expect } from '@playwright/test'
import { setupClaudrielAdminMocks, CLAUDRIEL_MOCK_ENTITY_TYPES } from './fixtures/claudrielSession'

test.describe('Admin smoke', () => {
  test.beforeEach(async ({ page }) => {
    await setupClaudrielAdminMocks(page)
  })

  test('loads Data dashboard shell with mocked session', async ({ page }) => {
    await page.goto('/data')
    await expect(page).toHaveTitle(/Claudriel/i)
    const main = page.locator('#main-content')
    await expect(main).toBeVisible({ timeout: 20_000 })
    await expect(main.getByRole('heading', { name: CLAUDRIEL_MOCK_ENTITY_TYPES[0].label })).toBeVisible()
  })
})
