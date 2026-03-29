import { test, expect, type Page, type Response } from '@playwright/test'
import { defaultClaudrielPhpOrigin } from '../devPorts'

const PHP_BASE = defaultClaudrielPhpOrigin()

function sseEvent(event: string, data: unknown): string {
  return `event: ${event}\ndata: ${JSON.stringify(data)}\n\n`
}

/** Wait until POST /api/internal/session/{uuid}/continue finishes (avoids racing the UI). */
function waitForContinuePost(page: Page, expectedStatus: number): Promise<Response> {
  return page.waitForResponse(
    response =>
      response.request().method() === 'POST' &&
      response.url().includes('/api/internal/session/') &&
      response.url().includes('/continue') &&
      response.status() === expectedStatus,
  )
}

test('test_turn_settings_ui', async ({ page }) => {
  await page.goto(`${PHP_BASE}/settings`)

  await expect(page.getByTestId('daily-turn-ceiling')).toBeVisible()
  await expect(page.getByTestId('turn-limit-quick_lookup')).toBeVisible()
  await expect(page.getByTestId('turn-limit-email_compose')).toBeVisible()
  await expect(page.getByTestId('turn-limit-brief_generation')).toBeVisible()
  await expect(page.getByTestId('turn-limit-research')).toBeVisible()
  await expect(page.getByTestId('turn-limit-general')).toBeVisible()
  await expect(page.getByTestId('turn-limit-onboarding')).toBeVisible()
})

test('test_continuation_prompt_appears', async ({ page }) => {
  let chatSendCall = 0

  await page.route('**/api/chat/send', async route => {
    chatSendCall += 1
    const session_id = 'sess-1'
    const message_id = chatSendCall === 1 ? 'msg-1' : 'msg-2'

    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ session_id, message_id }),
    })
  })

  // Make brief fallback fail fast so the dashboard uses its embedded initial payload.
  await page.route('**/stream/brief?transport=fallback*', async route => {
    await route.fulfill({ status: 500, body: '' })
  })

  await page.route('**/api/internal/session/**/continue', async route => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        continued_count: 1,
        new_turn_budget: 10,
        daily_turns_used: 250,
        daily_ceiling: 500,
      }),
    })
  })

  await page.route('**/stream/chat/*', async route => {
    const url = route.request().url()
    if (url.includes('/msg-1')) {
      const session_uuid = 'sess-1'
      const body =
        sseEvent('chat-needs-continuation', {
          session_uuid,
          turns_consumed: 25,
          message: 'I need more turns to complete this task.',
        }) +
        sseEvent('chat-done', { done: true, full_response: 'Partial response.' })

      await route.fulfill({
        status: 200,
        headers: {
          'Content-Type': 'text/event-stream',
        },
        body,
      })
      return
    }

    // If something unexpected streams, don't break other tests.
    await route.fallback()
  })

  await page.goto(`${PHP_BASE}/chat`)
  await page.fill('#messageInput', 'Need more turns')
  await page.click('#sendBtn')

  await expect(page.getByRole('button', { name: 'Continue' })).toBeVisible()
})

test('test_continuation_grants_more_turns', async ({ page }) => {
  let chatSendCall = 0

  await page.route('**/api/chat/send', async route => {
    chatSendCall += 1
    const session_id = 'sess-1'
    const message_id = chatSendCall === 1 ? 'msg-1' : 'msg-2'

    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ session_id, message_id }),
    })
  })

  await page.route('**/stream/brief?transport=fallback*', async route => {
    await route.fulfill({ status: 500, body: '' })
  })

  await page.route('**/api/internal/session/**/continue', async route => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        continued_count: 1,
        new_turn_budget: 10,
        daily_turns_used: 250,
        daily_ceiling: 500,
      }),
    })
  })

  await page.route('**/stream/chat/*', async route => {
    const url = route.request().url()
    if (url.includes('/msg-1')) {
      const session_uuid = 'sess-1'
      const body =
        sseEvent('chat-needs-continuation', {
          session_uuid,
          turns_consumed: 25,
          message: 'I need more turns to complete this task.',
        }) +
        sseEvent('chat-done', { done: true, full_response: 'Partial response.' })

      await route.fulfill({
        status: 200,
        headers: {
          'Content-Type': 'text/event-stream',
        },
        body,
      })
      return
    }

    if (url.includes('/msg-2')) {
      const body = sseEvent('chat-done', { done: true, full_response: 'Continued response.' })

      await route.fulfill({
        status: 200,
        headers: {
          'Content-Type': 'text/event-stream',
        },
        body,
      })
      return
    }

    await route.fallback()
  })

  await page.goto(`${PHP_BASE}/chat`)
  await page.fill('#messageInput', 'Need more turns')
  await page.click('#sendBtn')

  await expect(page.getByRole('button', { name: 'Continue' })).toBeVisible()
  const continueDone = waitForContinuePost(page, 200)
  await page.getByRole('button', { name: 'Continue' }).click()
  await continueDone

  await expect(page.getByText('Continued response.')).toBeVisible()
  await expect(page.locator('.continuation-bar')).toHaveCount(0)
})

test('test_daily_ceiling_blocks', async ({ page }) => {
  let chatSendCall = 0

  await page.route('**/api/chat/send', async route => {
    chatSendCall += 1
    const session_id = 'sess-1'
    const message_id = chatSendCall === 1 ? 'msg-1' : 'msg-2'

    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ session_id, message_id }),
    })
  })

  await page.route('**/stream/brief?transport=fallback*', async route => {
    await route.fulfill({ status: 500, body: '' })
  })

  await page.route('**/api/internal/session/**/continue', async route => {
    await route.fulfill({ status: 429, body: '' })
  })

  await page.route('**/stream/chat/*', async route => {
    const url = route.request().url()
    if (url.includes('/msg-1')) {
      const session_uuid = 'sess-1'
      const body =
        sseEvent('chat-needs-continuation', {
          session_uuid,
          turns_consumed: 25,
          message: 'I need more turns to complete this task.',
        }) +
        sseEvent('chat-done', { done: true, full_response: 'Partial response.' })

      await route.fulfill({
        status: 200,
        headers: {
          'Content-Type': 'text/event-stream',
        },
        body,
      })
      return
    }

    await route.fallback()
  })

  await page.goto(`${PHP_BASE}/chat`)
  await page.fill('#messageInput', 'Need more turns')
  await page.click('#sendBtn')

  await expect(page.getByRole('button', { name: 'Continue' })).toBeVisible()
  const continueDenied = waitForContinuePost(page, 429)
  await page.getByRole('button', { name: 'Continue' }).click()
  await continueDenied

  await expect(page.getByText('Daily turn limit reached. Please try again tomorrow.')).toBeVisible()
})

