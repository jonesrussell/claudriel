import { test, expect } from '@playwright/test'
import { setupClaudrielAdminMocks, CLAUDRIEL_MOCK_ENTITY_TYPES } from './fixtures/claudrielSession'

test.describe('Navigation', () => {
  test.beforeEach(async ({ page }) => {
    await setupClaudrielAdminMocks(page)
    await page.goto('/today')
    // Nav is inside ClientOnly; wait for first ops link before assertions.
    await expect(page.locator('nav').getByRole('link', { name: 'Today' })).toBeVisible({
      timeout: 15_000,
    })
  })

  test('renders the Today link', async ({ page }) => {
    await expect(page.locator('nav').getByRole('link', { name: 'Today' })).toBeVisible()
  })

  test('renders grouped nav section headings', async ({ page }) => {
    const sections = page.locator('.nav-section')
    await expect(sections.first()).toBeVisible()
  })

  test('renders entity type labels in the nav', async ({ page }) => {
    for (const et of CLAUDRIEL_MOCK_ENTITY_TYPES) {
      await expect(page.locator('nav').getByRole('link', { name: et.label, exact: true })).toBeVisible()
    }
  })
})
