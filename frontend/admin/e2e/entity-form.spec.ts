import { test, expect } from '@playwright/test'
import { mockClaudrielGraphql, mockClaudrielSchemaRoutes, mockClaudrielSession, personSchemaFixture } from './fixtures/claudrielSession'

test.describe('Entity create form', () => {
  test.beforeEach(async ({ page }) => {
    await mockClaudrielSession(page)
    await mockClaudrielGraphql(page)
    await mockClaudrielSchemaRoutes(page, { person: personSchemaFixture })
  })

  test('renders form fields from schema', async ({ page }) => {
    await page.goto('/person/create')
    await expect(page.getByLabel('Name')).toBeVisible({ timeout: 15_000 })
  })

  test('submits form and handles success', async ({ page }) => {
    await page.goto('/person/create')
    await expect(page.getByLabel('Name')).toBeVisible({ timeout: 15_000 })
    await page.getByLabel('Name').fill('testuser')
    await page.getByRole('button', { name: /create/i }).click()
    await expect(page).not.toHaveURL(/\/person\/create/)
  })
})
