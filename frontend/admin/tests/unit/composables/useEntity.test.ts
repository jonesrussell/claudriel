import { describe, it, expect, vi } from 'vitest'
import { useEntity } from '~/composables/useEntity'

function mockGraphqlResponse(data: Record<string, unknown>) {
  return vi.fn().mockResolvedValue({
    ok: true,
    json: () => Promise.resolve({ data }),
  })
}

describe('useEntity.search', () => {
  it('returns empty array when query is less than 2 characters', async () => {
    const mockFetch = vi.fn()
    vi.stubGlobal('fetch', mockFetch)
    const { search } = useEntity()
    expect(await search('user', 'name', '')).toEqual([])
    expect(await search('user', 'name', 'a')).toEqual([])
    expect(mockFetch).not.toHaveBeenCalled()
  })

  it('calls GraphQL with filter params when query is 2+ chars', async () => {
    const mockFetch = mockGraphqlResponse({
      personList: { items: [{ uuid: 'person-1', name: 'John' }], total: 1 },
    })
    vi.stubGlobal('fetch', mockFetch)
    const { search } = useEntity()
    const result = await search('person', 'name', 'jo')
    expect(mockFetch).toHaveBeenCalledWith('/graphql', expect.objectContaining({
      method: 'POST',
    }))
    expect(result).toHaveLength(1)
  })
})

describe('useEntity.list', () => {
  it('calls GraphQL for the requested entity type', async () => {
    const mockFetch = mockGraphqlResponse({
      personList: { items: [], total: 0 },
    })
    vi.stubGlobal('fetch', mockFetch)
    const { list } = useEntity()
    await list('person')
    expect(mockFetch).toHaveBeenCalledWith('/graphql', expect.objectContaining({
      method: 'POST',
    }))
  })

  it('returns normalized resources plus derived pagination meta', async () => {
    const mockFetch = mockGraphqlResponse({
      commitmentList: { items: [{ uuid: 'commitment-1', title: 'Hello' }], total: 1 },
    })
    vi.stubGlobal('fetch', mockFetch)
    const { list } = useEntity()
    const result = await list('commitment', { page: { offset: 25, limit: 10 } })
    expect(result.data).toEqual([{ type: 'commitment', id: 'commitment-1', attributes: { uuid: 'commitment-1', title: 'Hello' } }])
    expect(result.meta).toEqual({ total: 1, offset: 25, limit: 10 })
    expect(result.links).toEqual({})
  })
})

describe('useEntity.create', () => {
  it('sends GraphQL mutation with input attributes', async () => {
    const mockFetch = mockGraphqlResponse({
      createCommitment: { uuid: '1', title: 'New' },
    })
    vi.stubGlobal('fetch', mockFetch)
    const { create } = useEntity()
    const result = await create('commitment', { title: 'New' })
    expect(mockFetch).toHaveBeenCalledWith('/graphql', expect.objectContaining({
      method: 'POST',
    }))
    expect(result.id).toBe('1')
  })
})

describe('useEntity.get', () => {
  it('calls GraphQL query and returns the normalized resource', async () => {
    const mockFetch = mockGraphqlResponse({
      person: { uuid: '7', name: 'alice' },
    })
    vi.stubGlobal('fetch', mockFetch)
    const { get } = useEntity()
    const result = await get('person', '7')
    expect(mockFetch).toHaveBeenCalledWith('/graphql', expect.objectContaining({
      method: 'POST',
    }))
    expect(result).toEqual({ type: 'person', id: '7', attributes: { uuid: '7', name: 'alice' } })
  })
})

describe('useEntity.update', () => {
  it('sends GraphQL update mutation', async () => {
    const mockFetch = mockGraphqlResponse({
      updateWorkspace: { uuid: '3', name: 'Updated' },
    })
    vi.stubGlobal('fetch', mockFetch)
    const { update } = useEntity()
    await update('workspace', '3', { name: 'Updated' })
    expect(mockFetch).toHaveBeenCalledWith('/graphql', expect.objectContaining({
      method: 'POST',
    }))
  })
})

describe('useEntity.remove', () => {
  it('sends GraphQL delete mutation', async () => {
    const mockFetch = mockGraphqlResponse({
      deleteTriageEntry: { deleted: true },
    })
    vi.stubGlobal('fetch', mockFetch)
    const { remove } = useEntity()
    await remove('triage_entry', '5')
    expect(mockFetch).toHaveBeenCalledWith('/graphql', expect.objectContaining({
      method: 'POST',
    }))
  })
})

describe('useEntity unsupported types', () => {
  it('throws when the host adapter has no mapping for the entity type', async () => {
    vi.stubGlobal('fetch', vi.fn())
    const { list } = useEntity()
    await expect(list('node')).rejects.toThrow('Unsupported admin entity type: node')
  })
})

describe('useEntity error propagation', () => {
  it('propagates fetch errors to the caller', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('Network error')))
    const { list } = useEntity()
    await expect(list('person')).rejects.toThrow('Network error')
  })
})
