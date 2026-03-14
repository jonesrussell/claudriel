export interface JsonApiResource {
  type: string
  id: string
  attributes: Record<string, any>
  relationships?: Record<string, any>
  links?: Record<string, string>
  meta?: Record<string, any>
}

export interface JsonApiDocument {
  jsonapi: { version: string }
  data: JsonApiResource | JsonApiResource[] | null
  errors?: Array<{ status: string; title: string; detail?: string }>
  meta?: Record<string, any>
  links?: Record<string, string>
}

interface EntityConfig {
  basePath: string
  collectionKey: string
  itemKey: string
  labelField: string
}

const ENTITY_CONFIG: Record<string, EntityConfig> = {
  workspace: {
    basePath: '/api/workspaces',
    collectionKey: 'workspaces',
    itemKey: 'workspace',
    labelField: 'name',
  },
  person: {
    basePath: '/api/people',
    collectionKey: 'people',
    itemKey: 'person',
    labelField: 'name',
  },
  commitment: {
    basePath: '/api/commitments',
    collectionKey: 'commitments',
    itemKey: 'commitment',
    labelField: 'title',
  },
  schedule_entry: {
    basePath: '/api/schedule',
    collectionKey: 'schedule',
    itemKey: 'schedule',
    labelField: 'title',
  },
  triage_entry: {
    basePath: '/api/triage',
    collectionKey: 'triage',
    itemKey: 'triage',
    labelField: 'sender_name',
  },
}

export function useEntity() {
  function configFor(type: string): EntityConfig {
    const config = ENTITY_CONFIG[type]
    if (!config) {
      throw new Error(`Unsupported admin entity type: ${type}`)
    }

    return config
  }

  function toResource(type: string, item: Record<string, any>): JsonApiResource {
    const id = typeof item.uuid === 'string' && item.uuid !== ''
      ? item.uuid
      : String(item.id ?? '')

    return {
      type,
      id,
      attributes: { ...item },
    }
  }

  async function list(
    type: string,
    query: Record<string, any> = {},
  ): Promise<{ data: JsonApiResource[]; meta: Record<string, any>; links: Record<string, string> }> {
    const config = configFor(type)
    const response = await $fetch<Record<string, any>>(config.basePath)
    const items = Array.isArray(response[config.collectionKey]) ? response[config.collectionKey] : []
    const resources = items.map((item) => toResource(type, item))

    return {
      data: resources,
      meta: {
        total: resources.length,
        offset: typeof query.page?.offset === 'number' ? query.page.offset : 0,
        limit: typeof query.page?.limit === 'number' ? query.page.limit : resources.length,
      },
      links: {},
    }
  }

  async function get(type: string, id: string): Promise<JsonApiResource> {
    const config = configFor(type)
    const response = await $fetch<Record<string, any>>(`${config.basePath}/${id}`)
    const item = response[config.itemKey]
    if (!item || typeof item !== 'object') {
      throw new Error(`Failed to load ${type} ${id}`)
    }

    return toResource(type, item)
  }

  async function create(type: string, attributes: Record<string, any>): Promise<JsonApiResource> {
    const config = configFor(type)
    const response = await $fetch<Record<string, any>>(config.basePath, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: attributes,
    })

    return toResource(type, response[config.itemKey])
  }

  async function update(
    type: string,
    id: string,
    attributes: Record<string, any>,
  ): Promise<JsonApiResource> {
    const config = configFor(type)
    const response = await $fetch<Record<string, any>>(`${config.basePath}/${id}`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: attributes,
    })

    return toResource(type, response[config.itemKey])
  }

  async function remove(type: string, id: string): Promise<void> {
    const config = configFor(type)
    await $fetch(`${config.basePath}/${id}`, { method: 'DELETE' })
  }

  async function search(
    type: string,
    labelField: string,
    query: string,
    limit: number = 10,
  ): Promise<JsonApiResource[]> {
    if (query.length < 2) return []

    const config = configFor(type)
    const result = await list(type)
    const effectiveField = labelField || config.labelField
    const needle = query.toLowerCase()

    return result.data
      .filter((resource) => String(resource.attributes[effectiveField] ?? '').toLowerCase().includes(needle))
      .slice(0, limit)
  }

  return { list, get, create, update, remove, search }
}
