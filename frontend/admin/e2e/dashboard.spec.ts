import { test, expect } from '@playwright/test'
import { setupClaudrielAdminMocks, CLAUDRIEL_MOCK_ENTITY_TYPES } from './fixtures/claudrielSession'

test.describe('Dashboard', () => {
  test.beforeEach(async ({ page }) => {
    await setupClaudrielAdminMocks(page)
  })

  test('renders entity type cards with labels from session', async ({ page }) => {
    await page.goto('/data')
    const main = page.locator('#main-content')
    await expect(main).toBeVisible({ timeout: 15_000 })
    for (const et of CLAUDRIEL_MOCK_ENTITY_TYPES) {
      await expect(main.getByRole('heading', { name: et.label })).toBeVisible()
    }
  })

  test('each card links to the entity type route under /admin', async ({ page }) => {
    await page.goto('/data')
    const main = page.locator('#main-content')
    await expect(main).toBeVisible({ timeout: 15_000 })
    for (const et of CLAUDRIEL_MOCK_ENTITY_TYPES) {
      const link = main.getByRole('link', { name: et.label, exact: true })
      await expect(link).toBeVisible()
      await expect(link).toHaveAttribute('href', new RegExp(`/${et.id}/?$`))
    }
  })

  test('clicking a card navigates to the entity list', async ({ page }) => {
    await page.goto('/data')
    await expect(page.locator('#main-content')).toBeVisible({ timeout: 15_000 })
    await page.locator('#main-content').getByRole('link', { name: 'Person', exact: true }).click()
    await expect(page).toHaveURL(/\/admin\/person\/?$/)
  })
})
