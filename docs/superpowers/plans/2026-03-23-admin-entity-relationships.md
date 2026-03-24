# Admin Entity Relationships UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the generic entity detail pages in the Claudriel admin SPA with relationship-aware views using a hybrid shell + entity-specific config architecture.

**Architecture:** A shared `EntityDetailLayout` component renders a Sidebar + Main layout. Each entity type provides a config object defining metadata fields, sidebar relationship sections, and actions. Relationship panels fetch data via GraphQL, resolve junctions to target entities, and support link/unlink operations. An `ActivityTimeline` component provides a unified chronological event stream. The existing `SchemaForm` is reused as a "Details" sidebar section.

**Tech Stack:** Nuxt 3, Vue 3 (Composition API), TypeScript, Vitest, GraphQL via `claudrielAdapter.ts`

**Spec:** `docs/superpowers/specs/2026-03-23-admin-entity-relationships-design.md`

---

## File Map

### New Files (Shared Components)
- `frontend/admin/app/components/entity-detail/EntityDetailLayout.vue` — Sidebar + Main layout shell
- `frontend/admin/app/components/entity-detail/MetadataCard.vue` — Sidebar metadata display
- `frontend/admin/app/components/entity-detail/RelationshipPanel.vue` — Generic relationship table
- `frontend/admin/app/components/entity-detail/ActivityTimeline.vue` — Unified chronological timeline
- `frontend/admin/app/components/entity-detail/LinkDialog.vue` — Search + URL link dialog

### New Files (Config & Composables)
- `frontend/admin/app/composables/useEntityDetailConfig.ts` — Config registry composable
- `frontend/admin/app/composables/useRelationshipData.ts` — Fetch + resolve relationship data

### New Files (Entity Configs)
- `frontend/admin/app/components/entities/workspace/workspaceDetailConfig.ts`
- `frontend/admin/app/components/entities/project/projectDetailConfig.ts`
- `frontend/admin/app/components/entities/repo/repoDetailConfig.ts`
- `frontend/admin/app/components/entities/person/personDetailConfig.ts`
- `frontend/admin/app/components/entities/commitment/commitmentDetailConfig.ts`
- `frontend/admin/app/components/entities/schedule-entry/scheduleEntryDetailConfig.ts`
- `frontend/admin/app/components/entities/triage-entry/triageEntryDetailConfig.ts`
- `frontend/admin/app/components/entities/judgment-rule/judgmentRuleDetailConfig.ts`

### New Files (Tests)
- `frontend/admin/tests/unit/composables/useEntityDetailConfig.test.ts`
- `frontend/admin/tests/unit/composables/useRelationshipData.test.ts`
- `frontend/admin/tests/unit/components/entity-detail/EntityDetailLayout.test.ts`
- `frontend/admin/tests/unit/components/entity-detail/MetadataCard.test.ts`
- `frontend/admin/tests/unit/components/entity-detail/RelationshipPanel.test.ts`
- `frontend/admin/tests/unit/components/entity-detail/ActivityTimeline.test.ts`
- `frontend/admin/tests/unit/components/entity-detail/LinkDialog.test.ts`

### Modified Files
- `frontend/admin/app/pages/[entityType]/[id].vue` — Add config lookup, render EntityDetailLayout or SchemaForm

---

## Task 1: Config Types and Registry

**Files:**
- Create: `frontend/admin/app/composables/useEntityDetailConfig.ts`
- Test: `frontend/admin/tests/unit/composables/useEntityDetailConfig.test.ts`

- [ ] **Step 1: Write failing test for config registry**

```ts
// frontend/admin/tests/unit/composables/useEntityDetailConfig.test.ts
import { describe, it, expect } from 'vitest'
import { useEntityDetailConfig, type EntityDetailConfig } from '~/composables/useEntityDetailConfig'

describe('useEntityDetailConfig', () => {
  it('returns null for unknown entity type', () => {
    expect(useEntityDetailConfig('nonexistent')).toBeNull()
  })

  it('returns config for registered entity type', () => {
    const config = useEntityDetailConfig('workspace')
    expect(config).not.toBeNull()
    expect(config!.sidebar.length).toBeGreaterThan(0)
  })

  it('config has required metadata fields', () => {
    const config = useEntityDetailConfig('workspace')!
    expect(config.metadata).toBeDefined()
    expect(config.metadata!.length).toBeGreaterThan(0)
    expect(config.metadata![0]).toHaveProperty('key')
    expect(config.metadata![0]).toHaveProperty('label')
  })

  it('every sidebar section has key and label', () => {
    const config = useEntityDetailConfig('workspace')!
    for (const section of config.sidebar) {
      expect(section.key).toBeTruthy()
      expect(section.label).toBeTruthy()
    }
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run --prefix frontend/admin test -- --run tests/unit/composables/useEntityDetailConfig.test.ts`
Expected: FAIL — module not found

- [ ] **Step 3: Create config types and registry with workspace config**

```ts
// frontend/admin/app/composables/useEntityDetailConfig.ts
import type { Component } from 'vue'

export interface MetadataField {
  key: string
  label: string
  truncate?: boolean
  format?: 'date' | 'badge'
}

export interface RelationshipQuery {
  entityType: string
  filterField: string
  resolveType?: string
  resolveField?: string  // defaults to `${resolveType}_uuid`
}

export interface SidebarSection {
  key: string
  label: string
  query?: RelationshipQuery
  component?: Component
}

export interface ActionConfig {
  label: string
  type: 'link' | 'create' | 'custom'
  targetType?: string
  component?: Component
}

export interface EntityDetailConfig {
  sidebar: SidebarSection[]
  actions?: ActionConfig[]
  metadata?: MetadataField[]
}

const CONFIG_REGISTRY: Record<string, EntityDetailConfig> = {}

export function registerEntityDetailConfig(entityType: string, config: EntityDetailConfig): void {
  CONFIG_REGISTRY[entityType] = config
}

export function useEntityDetailConfig(entityType: string): EntityDetailConfig | null {
  return CONFIG_REGISTRY[entityType] ?? null
}
```

- [ ] **Step 4: Create workspace config**

```ts
// frontend/admin/app/components/entities/workspace/workspaceDetailConfig.ts
import { registerEntityDetailConfig, type EntityDetailConfig } from '~/composables/useEntityDetailConfig'

export const workspaceDetailConfig: EntityDetailConfig = {
  metadata: [
    { key: 'status', label: 'Status', format: 'badge' },
    { key: 'mode', label: 'Mode' },
    { key: 'created_at', label: 'Created', format: 'date' },
    { key: 'tenant_id', label: 'Tenant', truncate: true },
  ],
  sidebar: [
    {
      key: 'repos',
      label: 'Repos',
      query: {
        entityType: 'workspace_repo',
        filterField: 'workspace_uuid',
        resolveType: 'repo',
        resolveField: 'repo_uuid',
      },
    },
    {
      key: 'projects',
      label: 'Projects',
      query: {
        entityType: 'workspace_project',
        filterField: 'workspace_uuid',
        resolveType: 'project',
        resolveField: 'project_uuid',
      },
    },
    {
      key: 'activity',
      label: 'Activity',
    },
    {
      key: 'details',
      label: 'Details',
    },
  ],
  actions: [
    { label: 'Link Repo', type: 'link', targetType: 'repo' },
    { label: 'Link Project', type: 'link', targetType: 'project' },
  ],
}

registerEntityDetailConfig('workspace', workspaceDetailConfig)
```

- [ ] **Step 5: Import workspace config in test and re-run**

Add to top of test file:
```ts
import '~/components/entities/workspace/workspaceDetailConfig'
```

Run: `npm run --prefix frontend/admin test -- --run tests/unit/composables/useEntityDetailConfig.test.ts`
Expected: PASS (4 tests)

- [ ] **Step 6: Commit**

```bash
git add frontend/admin/app/composables/useEntityDetailConfig.ts \
  frontend/admin/app/components/entities/workspace/workspaceDetailConfig.ts \
  frontend/admin/tests/unit/composables/useEntityDetailConfig.test.ts
git commit -m "feat: add entity detail config types, registry, and workspace config"
```

---

## Task 2: MetadataCard Component

**Files:**
- Create: `frontend/admin/app/components/entity-detail/MetadataCard.vue`
- Test: `frontend/admin/tests/unit/components/entity-detail/MetadataCard.test.ts`

- [ ] **Step 1: Write failing test**

```ts
// frontend/admin/tests/unit/components/entity-detail/MetadataCard.test.ts
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import MetadataCard from '~/components/entity-detail/MetadataCard.vue'

describe('MetadataCard', () => {
  const fields = [
    { key: 'status', label: 'Status', format: 'badge' as const },
    { key: 'mode', label: 'Mode' },
    { key: 'tenant_id', label: 'Tenant', truncate: true },
    { key: 'created_at', label: 'Created', format: 'date' as const },
  ]

  const entity = {
    status: 'active',
    mode: 'persistent',
    tenant_id: '3d1984c2-4aa0-4af3-97bb-a9e6db4e8ce0',
    created_at: '2026-03-23T12:00:00Z',
  }

  it('renders all metadata fields', () => {
    const wrapper = mount(MetadataCard, { props: { fields, entity } })
    expect(wrapper.text()).toContain('Status')
    expect(wrapper.text()).toContain('active')
    expect(wrapper.text()).toContain('Mode')
    expect(wrapper.text()).toContain('persistent')
  })

  it('truncates long values when truncate is true', () => {
    const wrapper = mount(MetadataCard, { props: { fields, entity } })
    // Full UUID should not appear; truncated version should
    expect(wrapper.text()).not.toContain('3d1984c2-4aa0-4af3-97bb-a9e6db4e8ce0')
    expect(wrapper.text()).toContain('3d19')
  })

  it('renders empty state for missing values', () => {
    const wrapper = mount(MetadataCard, {
      props: { fields, entity: { status: 'active' } },
    })
    expect(wrapper.text()).toContain('Status')
    expect(wrapper.text()).toContain('active')
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run --prefix frontend/admin test -- --run tests/unit/components/entity-detail/MetadataCard.test.ts`
Expected: FAIL — component not found

- [ ] **Step 3: Implement MetadataCard**

```vue
<!-- frontend/admin/app/components/entity-detail/MetadataCard.vue -->
<script setup lang="ts">
import type { MetadataField } from '~/composables/useEntityDetailConfig'

const props = defineProps<{
  fields: MetadataField[]
  entity: Record<string, any>
}>()

function formatValue(field: MetadataField): string {
  const raw = props.entity[field.key]
  if (raw == null || raw === '') return '\u2014'
  if (field.format === 'date') {
    try { return new Date(raw).toLocaleDateString() } catch { return String(raw) }
  }
  if (field.truncate && typeof raw === 'string' && raw.length > 12) {
    return raw.slice(0, 4) + '\u2026' + raw.slice(-4)
  }
  return String(raw)
}
</script>

<template>
  <div class="metadata-card">
    <div v-for="field in fields" :key="field.key" class="metadata-field">
      <span class="metadata-label">{{ field.label }}</span>
      <span
        class="metadata-value"
        :class="{ 'metadata-badge': field.format === 'badge' }"
        :title="entity[field.key]"
      >
        {{ formatValue(field) }}
      </span>
    </div>
  </div>
</template>

<style scoped>
.metadata-card { display: flex; flex-direction: column; gap: 10px; padding: 12px; }
.metadata-field { display: flex; flex-direction: column; gap: 2px; }
.metadata-label { font-size: 10px; text-transform: uppercase; color: var(--color-text-muted, #999); letter-spacing: 0.05em; }
.metadata-value { font-size: 13px; }
.metadata-badge { font-weight: bold; }
</style>
```

- [ ] **Step 4: Run tests**

Run: `npm run --prefix frontend/admin test -- --run tests/unit/components/entity-detail/MetadataCard.test.ts`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add frontend/admin/app/components/entity-detail/MetadataCard.vue \
  frontend/admin/tests/unit/components/entity-detail/MetadataCard.test.ts
git commit -m "feat: add MetadataCard component for entity detail sidebar"
```

---

## Task 3: useRelationshipData Composable

**Files:**
- Create: `frontend/admin/app/composables/useRelationshipData.ts`
- Test: `frontend/admin/tests/unit/composables/useRelationshipData.test.ts`

- [ ] **Step 1: Write failing test**

```ts
// frontend/admin/tests/unit/composables/useRelationshipData.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { useRelationshipData } from '~/composables/useRelationshipData'
import type { RelationshipQuery } from '~/composables/useEntityDetailConfig'

const mockTransport = {
  list: vi.fn(),
  get: vi.fn(),
}

vi.mock('~/composables/useEntity', () => ({
  useEntity: () => mockTransport,
}))

describe('useRelationshipData', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('fetches count for a junction query', async () => {
    mockTransport.list.mockResolvedValue({
      data: [],
      meta: { total: 3 },
    })

    const query: RelationshipQuery = {
      entityType: 'workspace_repo',
      filterField: 'workspace_uuid',
      resolveType: 'repo',
      resolveField: 'repo_uuid',
    }

    const { count, fetchCount } = useRelationshipData(query, 'test-uuid')
    await fetchCount()

    expect(count.value).toBe(3)
    expect(mockTransport.list).toHaveBeenCalledWith(
      'workspace_repo',
      expect.objectContaining({
        filter: [{ field: 'workspace_uuid', value: 'test-uuid' }],
      }),
    )
  })

  it('fetches and resolves junction items', async () => {
    mockTransport.list
      .mockResolvedValueOnce({
        data: [
          { id: 'j1', attributes: { uuid: 'j1', workspace_uuid: 'w1', repo_uuid: 'r1' } },
          { id: 'j2', attributes: { uuid: 'j2', workspace_uuid: 'w1', repo_uuid: 'r2' } },
        ],
        meta: { total: 2 },
      })
      .mockResolvedValueOnce({
        data: [
          { id: 'r1', attributes: { uuid: 'r1', name: 'claudriel' } },
          { id: 'r2', attributes: { uuid: 'r2', name: 'waaseyaa' } },
        ],
        meta: { total: 2 },
      })

    const query: RelationshipQuery = {
      entityType: 'workspace_repo',
      filterField: 'workspace_uuid',
      resolveType: 'repo',
      resolveField: 'repo_uuid',
    }

    const { items, fetchItems } = useRelationshipData(query, 'w1')
    await fetchItems()

    expect(items.value).toHaveLength(2)
    expect(items.value[0].attributes.name).toBe('claudriel')
  })

  it('handles direct query (no junction resolution)', async () => {
    mockTransport.list.mockResolvedValue({
      data: [
        { id: 'c1', attributes: { uuid: 'c1', title: 'Send SOW' } },
      ],
      meta: { total: 1 },
    })

    const query: RelationshipQuery = {
      entityType: 'commitment',
      filterField: 'person_uuid',
    }

    const { items, fetchItems } = useRelationshipData(query, 'p1')
    await fetchItems()

    expect(items.value).toHaveLength(1)
    expect(items.value[0].attributes.title).toBe('Send SOW')
  })

  it('sets error on fetch failure', async () => {
    mockTransport.list.mockRejectedValue(new Error('Network error'))

    const query: RelationshipQuery = {
      entityType: 'workspace_repo',
      filterField: 'workspace_uuid',
    }

    const { error, fetchCount } = useRelationshipData(query, 'w1')
    await fetchCount()

    expect(error.value).toBe('Network error')
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run --prefix frontend/admin test -- --run tests/unit/composables/useRelationshipData.test.ts`
Expected: FAIL — module not found

- [ ] **Step 3: Implement useRelationshipData**

```ts
// frontend/admin/app/composables/useRelationshipData.ts
import { ref } from 'vue'
import type { JsonApiResource } from '~/host/types'
import type { RelationshipQuery } from '~/composables/useEntityDetailConfig'

export function useRelationshipData(query: RelationshipQuery, parentId: string) {
  const count = ref<number | null>(null)
  const items = ref<JsonApiResource[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  const { transport } = useHostAdapter()

  async function fetchCount() {
    try {
      error.value = null
      const result = await transport.list(query.entityType, {
        filter: [{ field: query.filterField, value: parentId }],
        page: { limit: 0, offset: 0 },
      })
      count.value = result.meta.total ?? 0
    } catch (e: any) {
      error.value = e.message ?? 'Failed to fetch count'
      count.value = null
    }
  }

  async function fetchItems() {
    try {
      loading.value = true
      error.value = null

      const result = await transport.list(query.entityType, {
        filter: [{ field: query.filterField, value: parentId }],
      })

      if (query.resolveType && query.resolveField) {
        const resolveField = query.resolveField ?? `${query.resolveType}_uuid`
        const targetUuids = result.data
          .map((item) => item.attributes[resolveField])
          .filter((uuid): uuid is string => typeof uuid === 'string' && uuid !== '')

        if (targetUuids.length > 0) {
          const resolved = await transport.list(query.resolveType, {
            filter: [{ field: 'uuid', value: targetUuids.join(','), operator: 'IN' }],
            page: { limit: targetUuids.length, offset: 0 },
          })
          items.value = resolved.data
        } else {
          items.value = []
        }
      } else {
        items.value = result.data
      }

      count.value = items.value.length
    } catch (e: any) {
      error.value = e.message ?? 'Failed to fetch items'
      items.value = []
    } finally {
      loading.value = false
    }
  }

  return { count, items, loading, error, fetchCount, fetchItems }
}
```

- [ ] **Step 4: Run tests**

Run: `npm run --prefix frontend/admin test -- --run tests/unit/composables/useRelationshipData.test.ts`
Expected: PASS (4 tests)

Note: the `useHostAdapter` mock may need adjustment based on the actual import pattern. Check `frontend/admin/app/composables/useEntity.ts` for the exact import. The test mocks `useEntity` since that's the existing pattern. Adjust the composable import if `useHostAdapter` is used differently.

- [ ] **Step 5: Commit**

```bash
git add frontend/admin/app/composables/useRelationshipData.ts \
  frontend/admin/tests/unit/composables/useRelationshipData.test.ts
git commit -m "feat: add useRelationshipData composable for junction resolution"
```

---

## Task 4: RelationshipPanel Component

**Files:**
- Create: `frontend/admin/app/components/entity-detail/RelationshipPanel.vue`
- Test: `frontend/admin/tests/unit/components/entity-detail/RelationshipPanel.test.ts`

- [ ] **Step 1: Write failing test**

```ts
// frontend/admin/tests/unit/components/entity-detail/RelationshipPanel.test.ts
import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import RelationshipPanel from '~/components/entity-detail/RelationshipPanel.vue'

vi.mock('~/composables/useRelationshipData', () => ({
  useRelationshipData: () => ({
    items: ref([
      { id: 'r1', type: 'repo', attributes: { uuid: 'r1', name: 'claudriel', full_name: 'jonesrussell/claudriel' } },
      { id: 'r2', type: 'repo', attributes: { uuid: 'r2', name: 'waaseyaa', full_name: 'jonesrussell/waaseyaa' } },
    ]),
    loading: ref(false),
    error: ref(null),
    fetchItems: vi.fn(),
  }),
}))

import { ref } from 'vue'

describe('RelationshipPanel', () => {
  const query = {
    entityType: 'workspace_repo',
    filterField: 'workspace_uuid',
    resolveType: 'repo',
    resolveField: 'repo_uuid',
  }

  it('renders a table of related entities', async () => {
    const wrapper = mount(RelationshipPanel, {
      props: { query, parentId: 'w1', entityType: 'workspace' },
    })
    await flushPromises()

    expect(wrapper.text()).toContain('claudriel')
    expect(wrapper.text()).toContain('waaseyaa')
  })

  it('renders unlink buttons for each row', async () => {
    const wrapper = mount(RelationshipPanel, {
      props: { query, parentId: 'w1', entityType: 'workspace' },
    })
    await flushPromises()

    const unlinkButtons = wrapper.findAll('[data-testid="unlink-btn"]')
    expect(unlinkButtons.length).toBe(2)
  })

  it('emits link event when link button clicked', async () => {
    const wrapper = mount(RelationshipPanel, {
      props: { query, parentId: 'w1', entityType: 'workspace' },
    })
    await flushPromises()

    await wrapper.find('[data-testid="link-btn"]').trigger('click')
    expect(wrapper.emitted('link')).toBeTruthy()
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run --prefix frontend/admin test -- --run tests/unit/components/entity-detail/RelationshipPanel.test.ts`
Expected: FAIL — component not found

- [ ] **Step 3: Implement RelationshipPanel**

```vue
<!-- frontend/admin/app/components/entity-detail/RelationshipPanel.vue -->
<script setup lang="ts">
import { onMounted } from 'vue'
import { useRelationshipData } from '~/composables/useRelationshipData'
import type { RelationshipQuery } from '~/composables/useEntityDetailConfig'

const props = defineProps<{
  query: RelationshipQuery
  parentId: string
  entityType: string
}>()

const emit = defineEmits<{
  link: []
  unlink: [targetId: string]
}>()

const { items, loading, error, fetchItems } = useRelationshipData(props.query, props.parentId)

onMounted(() => fetchItems())

const targetType = computed(() => props.query.resolveType ?? props.query.entityType)

const columns = computed(() => {
  if (items.value.length === 0) return []
  const attrs = items.value[0].attributes
  return Object.keys(attrs)
    .filter((k) => !['uuid', 'created_at', 'updated_at', 'tenant_id', 'account_id'].includes(k))
    .slice(0, 4)
})
</script>

<template>
  <div class="relationship-panel">
    <div class="panel-header">
      <h3>{{ query.resolveType ?? query.entityType }}</h3>
      <button data-testid="link-btn" class="btn btn-sm" @click="emit('link')">
        + Link
      </button>
    </div>

    <div v-if="loading" class="panel-loading">Loading...</div>

    <div v-else-if="error" class="panel-error">
      <span>{{ error }}</span>
      <button class="btn btn-sm" @click="fetchItems()">Retry</button>
    </div>

    <div v-else-if="items.length === 0" class="panel-empty">No linked {{ targetType }} entities.</div>

    <table v-else class="panel-table">
      <thead>
        <tr>
          <th v-for="col in columns" :key="col">{{ col }}</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="item in items" :key="item.id">
          <td v-for="col in columns" :key="col">
            <NuxtLink v-if="col === 'name' || col === 'title'" :to="`/${targetType}/${item.id}`">
              {{ item.attributes[col] }}
            </NuxtLink>
            <template v-else>{{ item.attributes[col] ?? '\u2014' }}</template>
          </td>
          <td class="actions">
            <button
              data-testid="unlink-btn"
              class="btn btn-sm btn-danger"
              @click="emit('unlink', item.id)"
            >
              Unlink
            </button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped>
.relationship-panel { padding: 16px; }
.panel-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
.panel-header h3 { margin: 0; font-size: 14px; text-transform: capitalize; }
.panel-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.panel-table th { text-align: left; padding: 6px; color: var(--color-text-muted, #999); border-bottom: 1px solid var(--color-border, #333); }
.panel-table td { padding: 6px; border-bottom: 1px solid var(--color-border-subtle, #222); }
.panel-table .actions { text-align: right; }
.panel-loading, .panel-empty { color: var(--color-text-muted, #888); padding: 24px; text-align: center; }
.panel-error { color: var(--color-error, #ef4444); padding: 12px; display: flex; justify-content: space-between; align-items: center; }
.btn-danger { color: var(--color-error, #ef4444); }
</style>
```

- [ ] **Step 4: Run tests**

Run: `npm run --prefix frontend/admin test -- --run tests/unit/components/entity-detail/RelationshipPanel.test.ts`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add frontend/admin/app/components/entity-detail/RelationshipPanel.vue \
  frontend/admin/tests/unit/components/entity-detail/RelationshipPanel.test.ts
git commit -m "feat: add RelationshipPanel component with unlink and retry"
```

---

## Task 5: ActivityTimeline Component

**Files:**
- Create: `frontend/admin/app/components/entity-detail/ActivityTimeline.vue`
- Test: `frontend/admin/tests/unit/components/entity-detail/ActivityTimeline.test.ts`

- [ ] **Step 1: Write failing test**

```ts
// frontend/admin/tests/unit/components/entity-detail/ActivityTimeline.test.ts
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ActivityTimeline from '~/components/entity-detail/ActivityTimeline.vue'

describe('ActivityTimeline', () => {
  const events = [
    { type: 'event', label: 'Repo claudriel linked', timestamp: '2026-03-23T15:00:00Z' },
    { type: 'triage', label: 'Email from Sarah Chen', timestamp: '2026-03-23T09:00:00Z' },
    { type: 'schedule', label: 'Sprint review', timestamp: '2026-03-22T14:00:00Z' },
    { type: 'commitment', label: 'Send revised SOW', timestamp: '2026-03-21T10:00:00Z' },
  ]

  it('renders all events in chronological order', () => {
    const wrapper = mount(ActivityTimeline, { props: { events } })
    expect(wrapper.text()).toContain('Repo claudriel linked')
    expect(wrapper.text()).toContain('Email from Sarah Chen')
    expect(wrapper.text()).toContain('Sprint review')
    expect(wrapper.text()).toContain('Send revised SOW')
  })

  it('renders filter chips for each event type', () => {
    const wrapper = mount(ActivityTimeline, { props: { events } })
    expect(wrapper.text()).toContain('All')
    expect(wrapper.text()).toContain('Events')
    expect(wrapper.text()).toContain('Triage')
    expect(wrapper.text()).toContain('Schedule')
    expect(wrapper.text()).toContain('Commitments')
  })

  it('filters events when chip is clicked', async () => {
    const wrapper = mount(ActivityTimeline, { props: { events } })
    await wrapper.find('[data-filter="triage"]').trigger('click')
    const timeline = wrapper.find('.timeline')
    expect(timeline.text()).toContain('Email from Sarah Chen')
    expect(timeline.text()).not.toContain('Sprint review')
  })

  it('renders empty state when no events', () => {
    const wrapper = mount(ActivityTimeline, { props: { events: [] } })
    expect(wrapper.text()).toContain('No activity')
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run --prefix frontend/admin test -- --run tests/unit/components/entity-detail/ActivityTimeline.test.ts`
Expected: FAIL — component not found

- [ ] **Step 3: Implement ActivityTimeline**

```vue
<!-- frontend/admin/app/components/entity-detail/ActivityTimeline.vue -->
<script setup lang="ts">
import { ref, computed } from 'vue'

export interface TimelineEvent {
  type: 'event' | 'triage' | 'schedule' | 'commitment'
  label: string
  timestamp: string
}

const props = defineProps<{ events: TimelineEvent[] }>()

const activeFilter = ref<string>('all')

const filters = [
  { key: 'all', label: 'All' },
  { key: 'event', label: 'Events' },
  { key: 'triage', label: 'Triage' },
  { key: 'schedule', label: 'Schedule' },
  { key: 'commitment', label: 'Commitments' },
]

const typeColors: Record<string, string> = {
  event: '#22c55e',
  triage: '#f59e0b',
  schedule: '#3b82f6',
  commitment: '#a855f7',
}

const typeLabels: Record<string, string> = {
  event: 'EVENT',
  triage: 'TRIAGE',
  schedule: 'SCHEDULE',
  commitment: 'COMMITMENT',
}

const filteredEvents = computed(() => {
  const sorted = [...props.events].sort(
    (a, b) => new Date(b.timestamp).getTime() - new Date(a.timestamp).getTime(),
  )
  if (activeFilter.value === 'all') return sorted
  return sorted.filter((e) => e.type === activeFilter.value)
})

function relativeTime(ts: string): string {
  const diff = Date.now() - new Date(ts).getTime()
  const hours = Math.floor(diff / 3600000)
  if (hours < 1) return 'just now'
  if (hours < 24) return `${hours}h ago`
  const days = Math.floor(hours / 24)
  return `${days}d ago`
}
</script>

<template>
  <div class="activity-timeline">
    <div class="filter-chips">
      <button
        v-for="f in filters"
        :key="f.key"
        :data-filter="f.key"
        :class="['chip', { active: activeFilter === f.key }]"
        @click="activeFilter = f.key"
      >
        {{ f.label }}
      </button>
    </div>

    <div v-if="filteredEvents.length === 0" class="empty">No activity</div>

    <div v-else class="timeline">
      <div v-for="(event, i) in filteredEvents" :key="i" class="timeline-item">
        <div class="dot" :style="{ background: typeColors[event.type] }"></div>
        <div class="timeline-content">
          <span class="time">{{ relativeTime(event.timestamp) }}</span>
          <div>
            <span class="type-label" :style="{ color: typeColors[event.type] }">
              {{ typeLabels[event.type] }}
            </span>
            {{ event.label }}
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.activity-timeline { padding: 16px; }
.filter-chips { display: flex; gap: 6px; margin-bottom: 14px; flex-wrap: wrap; }
.chip { background: var(--color-bg-subtle, #333); color: var(--color-text-muted, #888); padding: 2px 10px; border-radius: 12px; font-size: 11px; border: none; cursor: pointer; }
.chip.active { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
.timeline { border-left: 2px solid var(--color-border, #333); padding-left: 16px; }
.timeline-item { margin-bottom: 14px; position: relative; font-size: 13px; }
.dot { position: absolute; left: -21px; top: 4px; width: 10px; height: 10px; border-radius: 50%; }
.time { color: var(--color-text-muted, #999); font-size: 11px; }
.type-label { font-size: 11px; font-weight: bold; margin-right: 4px; }
.empty { color: var(--color-text-muted, #888); padding: 24px; text-align: center; }
</style>
```

- [ ] **Step 4: Run tests**

Run: `npm run --prefix frontend/admin test -- --run tests/unit/components/entity-detail/ActivityTimeline.test.ts`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add frontend/admin/app/components/entity-detail/ActivityTimeline.vue \
  frontend/admin/tests/unit/components/entity-detail/ActivityTimeline.test.ts
git commit -m "feat: add ActivityTimeline component with filter chips"
```

---

## Task 6: EntityDetailLayout Component

**Files:**
- Create: `frontend/admin/app/components/entity-detail/EntityDetailLayout.vue`
- Test: `frontend/admin/tests/unit/components/entity-detail/EntityDetailLayout.test.ts`

- [ ] **Step 1: Write failing test**

```ts
// frontend/admin/tests/unit/components/entity-detail/EntityDetailLayout.test.ts
import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import EntityDetailLayout from '~/components/entity-detail/EntityDetailLayout.vue'

vi.mock('~/composables/useRelationshipData', () => ({
  useRelationshipData: () => ({
    count: ref(2),
    items: ref([]),
    loading: ref(false),
    error: ref(null),
    fetchCount: vi.fn(),
    fetchItems: vi.fn(),
  }),
}))

import { ref } from 'vue'

describe('EntityDetailLayout', () => {
  const config = {
    metadata: [
      { key: 'status', label: 'Status', format: 'badge' as const },
    ],
    sidebar: [
      { key: 'repos', label: 'Repos', query: { entityType: 'workspace_repo', filterField: 'workspace_uuid', resolveType: 'repo', resolveField: 'repo_uuid' } },
      { key: 'projects', label: 'Projects', query: { entityType: 'workspace_project', filterField: 'workspace_uuid', resolveType: 'project', resolveField: 'project_uuid' } },
      { key: 'details', label: 'Details' },
    ],
    actions: [{ label: 'Link Repo', type: 'link' as const, targetType: 'repo' }],
  }

  const entity = { uuid: 'w1', name: 'smoke-test', status: 'active' }

  it('renders sidebar with section labels and counts', async () => {
    const wrapper = mount(EntityDetailLayout, {
      props: { config, entity, entityType: 'workspace' },
    })
    await flushPromises()

    expect(wrapper.text()).toContain('Repos')
    expect(wrapper.text()).toContain('Projects')
    expect(wrapper.text()).toContain('Details')
  })

  it('renders entity name in header', () => {
    const wrapper = mount(EntityDetailLayout, {
      props: { config, entity, entityType: 'workspace' },
    })
    expect(wrapper.text()).toContain('smoke-test')
  })

  it('renders action buttons in header', () => {
    const wrapper = mount(EntityDetailLayout, {
      props: { config, entity, entityType: 'workspace' },
    })
    expect(wrapper.text()).toContain('Link Repo')
  })

  it('selects first section by default', async () => {
    const wrapper = mount(EntityDetailLayout, {
      props: { config, entity, entityType: 'workspace' },
    })
    await flushPromises()

    const activeSection = wrapper.find('.sidebar-section.active')
    expect(activeSection.text()).toContain('Repos')
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run --prefix frontend/admin test -- --run tests/unit/components/entity-detail/EntityDetailLayout.test.ts`
Expected: FAIL — component not found

- [ ] **Step 3: Implement EntityDetailLayout**

```vue
<!-- frontend/admin/app/components/entity-detail/EntityDetailLayout.vue -->
<script setup lang="ts">
import { ref, onMounted } from 'vue'
import type { EntityDetailConfig, SidebarSection } from '~/composables/useEntityDetailConfig'
import { useRelationshipData } from '~/composables/useRelationshipData'
import MetadataCard from './MetadataCard.vue'
import RelationshipPanel from './RelationshipPanel.vue'

const props = defineProps<{
  config: EntityDetailConfig
  entity: Record<string, any>
  entityType: string
}>()

const emit = defineEmits<{
  saved: []
  error: [message: string]
}>()

const activeSection = ref<string>(props.config.sidebar[0]?.key ?? '')

const entityName = computed(() =>
  props.entity.name ?? props.entity.title ?? props.entity.uuid ?? 'Unknown',
)

// Fetch counts for sections with queries
const sectionCounts = ref<Record<string, number | null>>({})

onMounted(async () => {
  for (const section of props.config.sidebar) {
    if (section.query) {
      const { count, fetchCount } = useRelationshipData(section.query, props.entity.uuid)
      await fetchCount()
      sectionCounts.value[section.key] = count.value
    }
  }
})

function selectSection(key: string) {
  activeSection.value = key
}

function currentSection(): SidebarSection | undefined {
  return props.config.sidebar.find((s) => s.key === activeSection.value)
}
</script>

<template>
  <div class="entity-detail-layout">
    <header class="detail-header">
      <div class="header-title">
        <h1>{{ entityName }}</h1>
        <span v-if="entity.status" class="status-badge">{{ entity.status }}</span>
      </div>
      <div class="header-actions">
        <button
          v-for="action in config.actions"
          :key="action.label"
          class="btn btn-sm"
        >
          {{ action.label }}
        </button>
        <NuxtLink :to="`/${entityType}`" class="btn btn-sm">Back to list</NuxtLink>
      </div>
    </header>

    <div class="detail-body">
      <aside class="detail-sidebar">
        <MetadataCard
          v-if="config.metadata"
          :fields="config.metadata"
          :entity="entity"
        />
        <hr class="sidebar-divider" />
        <nav class="sidebar-sections">
          <button
            v-for="section in config.sidebar"
            :key="section.key"
            :class="['sidebar-section', { active: activeSection === section.key }]"
            @click="selectSection(section.key)"
          >
            <span>{{ section.label }}</span>
            <span v-if="sectionCounts[section.key] != null" class="count">
              {{ sectionCounts[section.key] }}
            </span>
          </button>
        </nav>
      </aside>

      <main class="detail-main">
        <template v-if="currentSection()">
          <component
            v-if="currentSection()!.component"
            :is="currentSection()!.component"
            :entity="entity"
            :entity-type="entityType"
          />
          <RelationshipPanel
            v-else-if="currentSection()!.query"
            :query="currentSection()!.query!"
            :parent-id="entity.uuid"
            :entity-type="entityType"
          />
          <SchemaForm
            v-else-if="currentSection()!.key === 'details'"
            :entity-type="entityType"
            :entity-id="entity.uuid"
            @saved="emit('saved')"
            @error="(msg) => emit('error', msg)"
          />
        </template>
      </main>
    </div>
  </div>
</template>

<style scoped>
.entity-detail-layout { display: flex; flex-direction: column; height: 100%; }
.detail-header { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; border-bottom: 1px solid var(--color-border, #333); }
.header-title { display: flex; align-items: center; gap: 10px; }
.header-title h1 { margin: 0; font-size: 18px; }
.status-badge { font-size: 11px; padding: 2px 8px; border-radius: 4px; background: rgba(34, 197, 94, 0.15); color: #22c55e; }
.header-actions { display: flex; gap: 8px; }
.detail-body { display: flex; flex: 1; min-height: 0; }
.detail-sidebar { width: 200px; border-right: 1px solid var(--color-border, #333); padding: 12px 0; overflow-y: auto; flex-shrink: 0; }
.sidebar-divider { border: none; border-top: 1px solid var(--color-border, #333); margin: 12px 0; }
.sidebar-sections { display: flex; flex-direction: column; gap: 2px; }
.sidebar-section { display: flex; justify-content: space-between; padding: 6px 12px; font-size: 13px; border: none; background: none; color: var(--color-text-muted, #888); cursor: pointer; text-align: left; border-left: 2px solid transparent; }
.sidebar-section.active { background: rgba(245, 158, 11, 0.08); border-left-color: #f59e0b; color: var(--color-text, #eee); }
.sidebar-section .count { font-size: 11px; background: var(--color-bg-subtle, #333); padding: 0 6px; border-radius: 8px; }
.detail-main { flex: 1; overflow-y: auto; }
</style>
```

- [ ] **Step 4: Run tests**

Run: `npm run --prefix frontend/admin test -- --run tests/unit/components/entity-detail/EntityDetailLayout.test.ts`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add frontend/admin/app/components/entity-detail/EntityDetailLayout.vue \
  frontend/admin/tests/unit/components/entity-detail/EntityDetailLayout.test.ts
git commit -m "feat: add EntityDetailLayout with sidebar navigation and panels"
```

---

## Task 7: LinkDialog Component

**Files:**
- Create: `frontend/admin/app/components/entity-detail/LinkDialog.vue`
- Test: `frontend/admin/tests/unit/components/entity-detail/LinkDialog.test.ts`

- [ ] **Step 1: Write failing test**

```ts
// frontend/admin/tests/unit/components/entity-detail/LinkDialog.test.ts
import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import LinkDialog from '~/components/entity-detail/LinkDialog.vue'

const mockSearch = vi.fn().mockResolvedValue([
  { id: 'r1', type: 'repo', attributes: { uuid: 'r1', name: 'claudriel' } },
])
const mockCreate = vi.fn().mockResolvedValue({ id: 'new', type: 'repo', attributes: {} })

vi.mock('~/composables/useEntity', () => ({
  useEntity: () => ({ search: mockSearch, create: mockCreate }),
}))

describe('LinkDialog', () => {
  it('renders search input', () => {
    const wrapper = mount(LinkDialog, {
      props: { targetType: 'repo', open: true },
    })
    expect(wrapper.find('input[data-testid="search-input"]').exists()).toBe(true)
  })

  it('shows search results after typing', async () => {
    const wrapper = mount(LinkDialog, {
      props: { targetType: 'repo', open: true },
    })
    await wrapper.find('[data-testid="search-input"]').setValue('claud')
    await flushPromises()

    expect(wrapper.text()).toContain('claudriel')
  })

  it('emits selected event when result clicked', async () => {
    const wrapper = mount(LinkDialog, {
      props: { targetType: 'repo', open: true },
    })
    await wrapper.find('[data-testid="search-input"]').setValue('claud')
    await flushPromises()

    await wrapper.find('[data-testid="result-item"]').trigger('click')
    expect(wrapper.emitted('selected')).toBeTruthy()
    expect(wrapper.emitted('selected')![0][0]).toBe('r1')
  })

  it('shows URL input for repo target type', () => {
    const wrapper = mount(LinkDialog, {
      props: { targetType: 'repo', open: true },
    })
    expect(wrapper.find('[data-testid="url-input"]').exists()).toBe(true)
  })

  it('hides URL input for non-repo target types', () => {
    const wrapper = mount(LinkDialog, {
      props: { targetType: 'project', open: true },
    })
    expect(wrapper.find('[data-testid="url-input"]').exists()).toBe(false)
  })

  it('is hidden when open is false', () => {
    const wrapper = mount(LinkDialog, {
      props: { targetType: 'repo', open: false },
    })
    expect(wrapper.find('.link-dialog').exists()).toBe(false)
  })
})
```

- [ ] **Step 2: Run test, implement, run test, commit**

Follow the same TDD pattern: verify fail, implement the component with search autocomplete + URL fallback mode, verify pass, commit.

```bash
git commit -m "feat: add LinkDialog component with search and URL modes"
```

---

## Task 8: Wire Detail Page with Config Fallback

**Files:**
- Modify: `frontend/admin/app/pages/[entityType]/[id].vue`
- Create: `frontend/admin/app/components/entities/workspace/index.ts` (re-export to trigger registration)

- [ ] **Step 1: Import all entity configs**

Create a barrel file that imports all configs to trigger registration:

```ts
// frontend/admin/app/components/entities/index.ts
import './workspace/workspaceDetailConfig'
// Future: import other entity configs here as they're added
```

- [ ] **Step 2: Modify [id].vue to check config registry**

```vue
<!-- frontend/admin/app/pages/[entityType]/[id].vue -->
<script setup lang="ts">
import '~/components/entities'
import { useLanguage } from '~/composables/useLanguage'
import { useSchema } from '~/composables/useSchema'
import { useEntityDetailConfig } from '~/composables/useEntityDetailConfig'

const route = useRoute()
const { t, entityLabel: translateEntityLabel } = useLanguage()
const { entityTypes } = useAuth()

const entityType = computed(() => route.params.entityType as string)
const { schema, fetch: fetchSchema } = useSchema(entityType.value)
const supportedType = computed(() => entityTypes.value.some((type) => type.id === entityType.value))
onMounted(() => fetchSchema())
const entityLabel = computed(() => translateEntityLabel(entityType.value, schema.value?.title ?? entityType.value))
const config = useRuntimeConfig()
useHead({ title: computed(() => `${t('edit_entity', { type: entityLabel.value })} | ${config.public.appName}`) })
const entityId = computed(() => route.params.id as string)
const successMessage = ref('')
const errorMessage = ref('')

// Check for relationship-aware config
const detailConfig = computed(() => useEntityDetailConfig(entityType.value))

// Fetch entity data for EntityDetailLayout
const { transport } = useHostAdapter()
const entity = ref<Record<string, any> | null>(null)
const entityLoading = ref(false)

async function loadEntity() {
  if (!detailConfig.value) return
  entityLoading.value = true
  try {
    const resource = await transport.get(entityType.value, entityId.value)
    entity.value = { ...resource.attributes, uuid: resource.id }
  } catch (e: any) {
    errorMessage.value = e.message ?? 'Failed to load entity'
  } finally {
    entityLoading.value = false
  }
}

onMounted(() => {
  if (detailConfig.value) loadEntity()
})

function onSaved() {
  successMessage.value = t('entity_saved')
  setTimeout(() => { successMessage.value = '' }, 3000)
}

function onError(message: string) {
  errorMessage.value = message
}
</script>

<template>
  <div>
    <div v-if="!supportedType" class="error">{{ t('error_not_found') }}</div>

    <div v-if="successMessage" class="success">{{ successMessage }}</div>
    <div v-if="errorMessage" class="error">{{ errorMessage }}</div>

    <!-- Relationship-aware layout -->
    <template v-if="detailConfig && entity">
      <EntityDetailLayout
        :config="detailConfig"
        :entity="entity"
        :entity-type="entityType"
        @saved="onSaved"
        @error="onError"
      />
    </template>

    <!-- Loading state for relationship layout -->
    <div v-else-if="detailConfig && entityLoading">Loading...</div>

    <!-- Fallback: generic schema form -->
    <template v-else-if="supportedType && !detailConfig">
      <div class="page-header">
        <h1>{{ t('edit_entity', { type: entityLabel }) }} #{{ entityId }}</h1>
        <NuxtLink :to="`/${entityType}`" class="btn">
          {{ t('back_to_list') }}
        </NuxtLink>
      </div>
      <SchemaForm
        :entity-type="entityType"
        :entity-id="entityId"
        @saved="onSaved"
        @error="onError"
      />
    </template>
  </div>
</template>
```

- [ ] **Step 3: Verify existing tests still pass**

Run: `npm run --prefix frontend/admin test -- --run`
Expected: All existing tests pass (no regressions)

- [ ] **Step 4: Commit**

```bash
git add frontend/admin/app/pages/\[entityType\]/\[id\].vue \
  frontend/admin/app/components/entities/index.ts
git commit -m "feat: wire entity detail page with config fallback to SchemaForm"
```

---

## Task 9: Remaining Entity Configs

**Files:**
- Create: 7 config files (project, repo, person, commitment, schedule-entry, triage-entry, judgment-rule)

- [ ] **Step 1: Create all remaining configs**

Each config follows the same pattern as workspace. Create one file per entity type following the sidebar maps from the spec. Register each via `registerEntityDetailConfig()`.

Key differences per entity:
- **project**: 5 sidebar sections (repos, workspaces, people, commitments, activity, details). People section uses a custom component (deferred to future task).
- **repo**: 3 sections (workspaces, projects, activity, details)
- **person**: 4 sections (projects uses custom component, commitments, triage entries, events, details)
- **commitment**: 3 sections (person as single-entity lookup, project, related events, details)
- **schedule_entry**: 2 sections (person, project, details)
- **triage_entry**: 3 sections (person, project, related commitments, details)
- **judgment_rule**: 1 section (issue runs, details)

For Person projects and Commitment person (single-entity lookups), use `query` with direct filter. Custom multi-hop components are deferred (marked with `// TODO: custom component for multi-hop resolution`).

- [ ] **Step 2: Update barrel import**

```ts
// frontend/admin/app/components/entities/index.ts
import './workspace/workspaceDetailConfig'
import './project/projectDetailConfig'
import './repo/repoDetailConfig'
import './person/personDetailConfig'
import './commitment/commitmentDetailConfig'
import './schedule-entry/scheduleEntryDetailConfig'
import './triage-entry/triageEntryDetailConfig'
import './judgment-rule/judgmentRuleDetailConfig'
```

- [ ] **Step 3: Write config validation tests**

```ts
// Add to useEntityDetailConfig.test.ts
const ALL_TYPES = ['workspace', 'project', 'repo', 'person', 'commitment', 'schedule_entry', 'triage_entry', 'judgment_rule']

for (const type of ALL_TYPES) {
  it(`returns config for ${type}`, () => {
    const config = useEntityDetailConfig(type)
    expect(config).not.toBeNull()
    expect(config!.sidebar.length).toBeGreaterThan(0)
    expect(config!.sidebar.some(s => s.key === 'details')).toBe(true)
  })
}
```

- [ ] **Step 4: Run tests, commit**

Run: `npm run --prefix frontend/admin test -- --run`
Expected: All tests pass

```bash
git add frontend/admin/app/components/entities/
git commit -m "feat: add entity detail configs for all 8 entity types"
```

---

## Task 10: Build, Deploy, and Smoke Test

- [ ] **Step 1: Run full test suite**

Run: `npm run --prefix frontend/admin test -- --run`
Expected: All tests pass

- [ ] **Step 2: Verify TypeScript compiles**

Run: `cd frontend/admin && npx nuxi typecheck`
Expected: No new errors (pre-existing errors are acceptable)

- [ ] **Step 3: Push and monitor deploy**

```bash
git push origin main
gh run watch $(gh run list --limit 1 --workflow "Deploy Claudriel" --json databaseId -q '.[0].databaseId') --exit-status
```

- [ ] **Step 4: Smoke test workspace detail on production**

Navigate to `https://claudriel.ai/admin/workspace/<uuid>` and verify:
- Sidebar renders with Repos, Projects, Activity, Details sections
- Clicking a section switches the main panel
- Relationship counts appear in sidebar
- Details tab shows the SchemaForm
- Back to list link works

- [ ] **Step 5: Commit any smoke test fixes**

---

## Implementation Notes (from plan review)

The following issues were identified during review. They are all fixable during TDD and do not affect the task ordering or architecture. The implementing agent should address them as they arise:

1. **Workspace config should set `component` on activity and details sections.** The activity section needs `component: ActivityTimeline` and details needs `component: EntityEditForm` (a thin wrapper around SchemaForm). Without these, EntityDetailLayout won't know what to render for those sections. Either add the components to the config or handle by key name in the layout (current approach for `details`).

2. **ActivityTimeline needs a data-fetching composable.** The current implementation accepts a static `events` prop. A `useActivityData(entityType, entityId)` composable should fetch McEvents (filtered by `scope: "{entityType}:{uuid}"`), ScheduleEntries, TriageEntries, and Commitments, then merge and sort them chronologically. Build this as part of Task 5 or as a follow-up task.

3. **`useRelationshipData` should use the same transport access pattern as existing composables.** Check how `useEntity.ts` accesses the adapter transport. The test mocks should match the actual import path. If the adapter is accessed via `useHostAdapter()` from `~/host/`, mock that path. If via `useEntity()`, mock that.

4. **Add missing Vue imports in component `<script setup>` blocks.** `computed` must be imported in RelationshipPanel and EntityDetailLayout. Nuxt auto-imports `ref`, `computed`, `onMounted` etc., so explicit imports may not be needed in the actual Nuxt build, but tests running outside Nuxt require them. Use `import { ref, computed, onMounted } from 'vue'` in all components.

5. **`useHostAdapter` in `[id].vue` (Task 8) needs explicit import** if it's not in the Nuxt auto-import directory. Check where the existing composables access the adapter transport.

6. **Sidebar count fetches should use `Promise.all` not sequential awaits.** In EntityDetailLayout's `onMounted`, fire all count fetches in parallel so each section loads independently.

7. **LinkDialog (Task 7) needs full implementation code.** The plan provides only tests. Implement: a modal/dialog with search input using debounced `transport.search()`, result list with click-to-select, conditional URL input for `targetType === 'repo'`, and emits `selected(uuid)` / `close`.

8. **Add a test for the `[id].vue` config branching logic (Task 8).** Test that when `useEntityDetailConfig` returns a config, `EntityDetailLayout` renders. When it returns null, `SchemaForm` renders.

9. **Test file locations:** existing component tests are in `tests/components/`, composable tests in `tests/unit/composables/`. Follow the existing convention for each type.

10. **`ref()` in mock factories:** Vitest hoists `vi.mock()` calls above imports. If the mock factory uses `ref()`, import it inside the factory: `vi.mock('...', async () => { const { ref } = await import('vue'); return { ... } })`.
