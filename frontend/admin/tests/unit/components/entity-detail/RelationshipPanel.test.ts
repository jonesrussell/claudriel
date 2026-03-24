import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import RelationshipPanel from '~/components/entity-detail/RelationshipPanel.vue'

const mockItems = ref([
  { id: 'r1', type: 'repo', attributes: { uuid: 'r1', name: 'claudriel', full_name: 'jonesrussell/claudriel' } },
  { id: 'r2', type: 'repo', attributes: { uuid: 'r2', name: 'waaseyaa', full_name: 'jonesrussell/waaseyaa' } },
])
const mockLoading = ref(false)
const mockError = ref<string | null>(null)
const mockFetchItems = vi.fn()

vi.mock('~/composables/useRelationshipData', () => ({
  useRelationshipData: () => ({
    items: mockItems,
    loading: mockLoading,
    error: mockError,
    fetchItems: mockFetchItems,
  }),
}))

// Stub NuxtLink as a plain anchor
const stubs = {
  NuxtLink: {
    template: '<a :href="to"><slot /></a>',
    props: ['to'],
  },
}

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
      global: { stubs },
    })
    await flushPromises()

    expect(wrapper.text()).toContain('claudriel')
    expect(wrapper.text()).toContain('waaseyaa')
  })

  it('renders unlink buttons for each row', async () => {
    const wrapper = mount(RelationshipPanel, {
      props: { query, parentId: 'w1', entityType: 'workspace' },
      global: { stubs },
    })
    await flushPromises()

    const unlinkButtons = wrapper.findAll('[data-testid="unlink-btn"]')
    expect(unlinkButtons.length).toBe(2)
  })

  it('emits link event when link button clicked', async () => {
    const wrapper = mount(RelationshipPanel, {
      props: { query, parentId: 'w1', entityType: 'workspace' },
      global: { stubs },
    })
    await flushPromises()

    await wrapper.find('[data-testid="link-btn"]').trigger('click')
    expect(wrapper.emitted('link')).toBeTruthy()
  })

  it('emits unlink with target id when unlink clicked', async () => {
    const wrapper = mount(RelationshipPanel, {
      props: { query, parentId: 'w1', entityType: 'workspace' },
      global: { stubs },
    })
    await flushPromises()

    const unlinkButtons = wrapper.findAll('[data-testid="unlink-btn"]')
    await unlinkButtons[0].trigger('click')
    expect(wrapper.emitted('unlink')![0]).toEqual(['r1'])
  })

  it('shows loading state', async () => {
    mockLoading.value = true
    mockItems.value = []

    const wrapper = mount(RelationshipPanel, {
      props: { query, parentId: 'w1', entityType: 'workspace' },
      global: { stubs },
    })
    await flushPromises()

    expect(wrapper.text()).toContain('Loading...')
    mockLoading.value = false
  })

  it('shows error with retry button', async () => {
    mockError.value = 'Network error'
    mockItems.value = []
    mockLoading.value = false

    const wrapper = mount(RelationshipPanel, {
      props: { query, parentId: 'w1', entityType: 'workspace' },
      global: { stubs },
    })
    await flushPromises()

    expect(wrapper.text()).toContain('Network error')
    expect(wrapper.text()).toContain('Retry')
    mockError.value = null
  })

  it('shows empty state when no items', async () => {
    mockItems.value = []
    mockError.value = null
    mockLoading.value = false

    const wrapper = mount(RelationshipPanel, {
      props: { query, parentId: 'w1', entityType: 'workspace' },
      global: { stubs },
    })
    await flushPromises()

    expect(wrapper.text()).toContain('No linked repo entities')

    // Reset
    mockItems.value = [
      { id: 'r1', type: 'repo', attributes: { uuid: 'r1', name: 'claudriel', full_name: 'jonesrussell/claudriel' } },
      { id: 'r2', type: 'repo', attributes: { uuid: 'r2', name: 'waaseyaa', full_name: 'jonesrussell/waaseyaa' } },
    ]
  })

  it('calls fetchItems on mount', () => {
    mount(RelationshipPanel, {
      props: { query, parentId: 'w1', entityType: 'workspace' },
      global: { stubs },
    })
    expect(mockFetchItems).toHaveBeenCalled()
  })
})
