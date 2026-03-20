export interface CodifiedContextSession {
  id: string
  sessionId: string
  repoHash: string
  startedAt: string
  endedAt: string | null
  durationMs: number | null
  eventCount: number
  latestDriftScore: number | null
  latestSeverity: string | null
}

export interface CodifiedContextEvent {
  id: string
  sessionId: string
  eventType: string
  data: Record<string, unknown>
  createdAt: string
}

export interface ValidationReport {
  sessionId: string
  driftScore: number
  components: {
    semantic_alignment: number
    structural_checks: number
    contradiction_checks: number
  }
  issues: Array<{ type: string; message: string; severity: string }>
  recommendation: string
  validatedAt: string
}

export function useCodifiedContext() {
  const sessions = ref<CodifiedContextSession[]>([])
  const currentSession = ref<CodifiedContextSession | null>(null)
  const events = ref<CodifiedContextEvent[]>([])
  const validationReport = ref<ValidationReport | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchSessions(limit = 50) {
    loading.value = true
    error.value = null
    try {
      const response = await fetch(`/api/telescope/codified-context/sessions?limit=${limit}`)
      const json = await response.json() as { data: CodifiedContextSession[] }
      sessions.value = json.data ?? []
    } catch (e: any) {
      error.value = e?.data?.errors?.[0]?.detail ?? e?.message ?? 'Failed to load sessions.'
    } finally {
      loading.value = false
    }
  }

  async function fetchSession(id: string) {
    loading.value = true
    error.value = null
    try {
      const response = await fetch(`/api/telescope/codified-context/sessions/${id}`)
      const json = await response.json() as { data: CodifiedContextSession }
      currentSession.value = json.data ?? null
    } catch (e: any) {
      error.value = e?.data?.errors?.[0]?.detail ?? e?.message ?? 'Failed to load session.'
    } finally {
      loading.value = false
    }
  }

  async function fetchEvents(id: string) {
    loading.value = true
    error.value = null
    try {
      const response = await fetch(`/api/telescope/codified-context/sessions/${id}/events`)
      const json = await response.json() as { data: CodifiedContextEvent[] }
      events.value = json.data ?? []
    } catch (e: any) {
      error.value = e?.data?.errors?.[0]?.detail ?? e?.message ?? 'Failed to load events.'
    } finally {
      loading.value = false
    }
  }

  async function fetchValidation(id: string) {
    loading.value = true
    error.value = null
    try {
      const response = await fetch(`/api/telescope/codified-context/sessions/${id}/validation`)
      const json = await response.json() as { data: ValidationReport }
      validationReport.value = json.data ?? null
    } catch (e: any) {
      error.value = e?.data?.errors?.[0]?.detail ?? e?.message ?? 'Failed to load validation.'
    } finally {
      loading.value = false
    }
  }

  return {
    sessions,
    currentSession,
    events,
    validationReport,
    loading,
    error,
    fetchSessions,
    fetchSession,
    fetchEvents,
    fetchValidation,
  }
}
