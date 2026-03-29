// Claudriel admin: session + API mocks (replaces Waaseyaa JSON:API fixtures).
import type { Page } from '@playwright/test'

/** Minimal schema shape for /api/schema mocks (matches EntitySchema). */
type SchemaFixture = {
  $schema: string
  title: string
  description: string
  type: string
  'x-entity-type': string
  'x-translatable': boolean
  'x-revisionable': boolean
  properties: Record<string, Record<string, unknown>>
  required?: string[]
}

/** Minimal entity_types payload matching /admin/session (see AdminSessionPayload). */
export const CLAUDRIEL_MOCK_ENTITY_TYPES = [
  {
    id: 'person',
    label: 'Person',
    keys: { id: 'pid', uuid: 'uuid', label: 'name' },
    group: 'people',
    disabled: false,
  },
  {
    id: 'workspace',
    label: 'Workspace',
    keys: { id: 'wid', uuid: 'uuid', label: 'name' },
    group: 'structure',
    disabled: false,
  },
  {
    id: 'commitment',
    label: 'Commitment',
    keys: { id: 'cid', uuid: 'uuid', label: 'title' },
    group: 'workflows',
    disabled: false,
  },
  {
    id: 'pipeline_config',
    label: 'Pipeline Config',
    keys: { id: 'id', uuid: 'uuid', label: 'name' },
    group: 'workflows',
    disabled: false,
  },
]

const MOCK_SESSION = {
  account: {
    uuid: '00000000-0000-4000-8000-000000000001',
    email: 'e2e@claudriel.test',
    tenant_id: 'tenant-e2e-uuid',
    roles: ['admin'],
  },
  tenant: null as const,
  entity_types: CLAUDRIEL_MOCK_ENTITY_TYPES,
}

/** Mock GET /admin/session so useAuth loads a logged-in Claudriel session. */
export async function mockClaudrielSession(page: Page): Promise<void> {
  await page.route('**/admin/session', (route) => {
    if (route.request().method() !== 'GET') {
      return route.fallback()
    }
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(MOCK_SESSION),
    })
  })
}

/** Minimal JSON Schema for list/create pages (person). */
export const personSchemaFixture: SchemaFixture = {
  $schema: 'http://json-schema.org/draft-07/schema#',
  title: 'Person',
  description: 'A person',
  type: 'object',
  'x-entity-type': 'person',
  'x-translatable': false,
  'x-revisionable': false,
  properties: {
    uuid: { type: 'string', readOnly: true, 'x-widget': 'hidden', 'x-weight': -10 },
    name: {
      type: 'string',
      'x-widget': 'text',
      'x-label': 'Name',
      'x-weight': 0,
      'x-required': true,
    },
    email: {
      type: 'string',
      format: 'email',
      'x-widget': 'email',
      'x-label': 'Email',
      'x-weight': 1,
    },
  },
  required: ['name'],
}

/** Mock GET /api/schema/:type for types used in tests. */
export async function mockClaudrielSchemaRoutes(
  page: Page,
  schemas: Record<string, SchemaFixture> = { person: personSchemaFixture },
): Promise<void> {
  await page.route('**/api/schema/**', (route) => {
    if (route.request().method() !== 'GET') {
      return route.fallback()
    }
    const url = route.request().url()
    const match = url.match(/\/api\/schema\/([^/?]+)/)
    const type = match ? decodeURIComponent(match[1]) : ''
    const schema = schemas[type]
    if (!schema) {
      return route.fulfill({
        status: 404,
        contentType: 'application/json',
        body: JSON.stringify({ errors: [{ detail: 'Unknown type' }] }),
      })
    }
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ meta: { schema } }),
    })
  })
}

/**
 * Mock POST /graphql: empty list queries + optional create mutations for e2e.
 * Matches Claudriel list field names (camelCase + List).
 */
export async function mockClaudrielGraphql(page: Page): Promise<void> {
  await page.route('**/graphql', async (route) => {
    if (route.request().method() !== 'POST') {
      return route.fallback()
    }

    let body: { query?: string; variables?: Record<string, unknown> }
    try {
      body = route.request().postDataJSON() as { query?: string; variables?: Record<string, unknown> }
    } catch {
      return route.fulfill({ status: 400, body: '{}' })
    }

    const query = body.query ?? ''
    const data: Record<string, unknown> = {}

    // List queries: personList, workspaceList, …
    const listFields = [...query.matchAll(/\b([a-z][a-zA-Z0-9]*List)\s*\(/g)].map(m => m[1])
    for (const field of listFields) {
      data[field] = { items: [], total: 0 }
    }

    // createPerson mutation
    if (query.includes('createPerson') && query.includes('mutation')) {
      data.createPerson = {
        uuid: 'new-person-uuid',
        name: 'testuser',
        email: null,
        tier: null,
        source: null,
        tenant_id: 'tenant-e2e-uuid',
        latest_summary: null,
        last_interaction_at: null,
        last_inbox_category: null,
        importance_score: null,
        access_count: null,
        last_accessed_at: null,
        created_at: '2026-01-01T00:00:00Z',
        updated_at: '2026-01-01T00:00:00Z',
      }
    }

    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data }),
    })
  })
}

/** Optional: mock ingest_log list for IngestSummaryWidget (silent failure still OK). */
export async function mockIngestLogEmpty(page: Page): Promise<void> {
  await page.route('**/api/ingest_log**', (route) =>
    route.fulfill({
      json: {
        jsonapi: { version: '1.0' },
        data: [],
        meta: { total: 0 },
        links: {},
      },
    }),
  )
}

/** Minimal GET /brief JSON so Today page `useDayBrief().refresh()` succeeds (Nitro proxies /brief to PHP). */
export async function mockBriefJson(page: Page): Promise<void> {
  await page.route('**/brief**', (route) => {
    if (route.request().method() !== 'GET') {
      return route.fallback()
    }
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        schedule: [],
        temporal_suggestions: [],
        people: [],
        triage: [],
        commitments: { pending: [], drifting: [], waiting_on: [] },
        follow_ups: [],
        counts: {
          due_today: 0,
          drifting: 0,
          waiting_on: 0,
          follow_ups: 0,
          triage: 0,
        },
      }),
    })
  })
}

/** Apply all Claudriel API mocks needed for dashboard + entity list smoke tests. */
export async function setupClaudrielAdminMocks(page: Page): Promise<void> {
  await mockClaudrielSession(page)
  await mockClaudrielGraphql(page)
  await mockClaudrielSchemaRoutes(page)
  await mockIngestLogEmpty(page)
  await mockBriefJson(page)
}
