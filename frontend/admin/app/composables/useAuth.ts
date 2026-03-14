import type { EntityTypeInfo } from '~/composables/useNavGroups'

export interface AuthUser {
  id: string
  email: string
  tenantId: string
  roles: string[]
}

interface TenantContext {
  uuid: string
  name: string
  default_workspace_uuid?: string | null
}

interface AdminSessionResponse {
  account: {
    uuid: string
    email: string
    tenant_id: string
    roles: string[]
  }
  tenant: TenantContext | null
  entity_types: EntityTypeInfo[]
}

const STATE_KEY = 'claudriel.admin.session.user'
const CHECKED_KEY = 'claudriel.admin.session.checked'
const TENANT_KEY = 'claudriel.admin.session.tenant'
const ENTITY_TYPES_KEY = 'claudriel.admin.session.entity-types'

export function useAuth() {
  const currentUser = useState<AuthUser | null>(STATE_KEY, () => null)
  const authChecked = useState<boolean>(CHECKED_KEY, () => false)
  const tenant = useState<TenantContext | null>(TENANT_KEY, () => null)
  const entityTypes = useState<EntityTypeInfo[]>(ENTITY_TYPES_KEY, () => [])
  const isAuthenticated = computed(() => currentUser.value !== null)

  async function fetchSession(): Promise<void> {
    try {
      const response = await $fetch<AdminSessionResponse>('/admin/session')
      currentUser.value = {
        id: response.account.uuid,
        email: response.account.email,
        tenantId: response.account.tenant_id,
        roles: response.account.roles ?? [],
      }
      tenant.value = response.tenant ?? null
      entityTypes.value = Array.isArray(response.entity_types) ? response.entity_types : []
    } catch {
      currentUser.value = null
      tenant.value = null
      entityTypes.value = []
    }
  }

  async function checkAuth(): Promise<void> {
    if (authChecked.value) {
      return
    }

    await fetchSession()
    authChecked.value = true
  }

  function loginUrl(path: string = '/admin'): string {
    return `/login?redirect=${encodeURIComponent(path)}`
  }

  async function logout(): Promise<void> {
    await $fetch('/admin/logout', { method: 'POST' })
    currentUser.value = null
    authChecked.value = false
    tenant.value = null
    entityTypes.value = []
  }

  return { currentUser, entityTypes, isAuthenticated, tenant, fetchSession, checkAuth, loginUrl, logout }
}
