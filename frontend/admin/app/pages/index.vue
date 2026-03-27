<script setup lang="ts">
import { useLanguage } from '~/composables/useLanguage'

const { t, entityLabel } = useLanguage()
const config = useRuntimeConfig()
useHead({ title: computed(() => `${t('dashboard')} | ${config.public.appName}`) })
const { entityTypes } = useAuth()

const ENTITY_DESCRIPTIONS: Record<string, string> = {
  workspace: 'Isolated contexts for clients or domains',
  project: 'Track ongoing work and link to repos',
  person: 'Contacts, clients, and collaborators',
  commitment: 'Promises made and received',
  schedule_entry: 'Meetings and time-blocked events',
  triage_entry: 'Inbox items awaiting a decision',
  pipeline_config: 'Lead pipeline settings per workspace',
  prospect: 'Leads moving through the pipeline',
  filtered_prospect: 'Leads rejected by the filter step',
  prospect_attachment: 'Files attached to prospects',
  prospect_audit: 'Audit trail for prospect changes',
}
</script>

<template>
  <div>
    <div class="page-header">
      <h1>{{ t('dashboard') }}</h1>
    </div>

    <IngestSummaryWidget />

    <div class="card-grid">
      <NuxtLink
        v-for="et in entityTypes"
        :key="et.id"
        :to="`/${et.id}`"
        class="card"
      >
        <h2 class="card-title">{{ entityLabel(et.id, et.label) }}</h2>
        <p v-if="ENTITY_DESCRIPTIONS[et.id]" class="card-sub">{{ ENTITY_DESCRIPTIONS[et.id] }}</p>
      </NuxtLink>
    </div>
  </div>
</template>

<style scoped>
.card-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 16px;
}
.card {
  background: var(--bg-surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-md, 10px);
  padding: 20px;
  text-decoration: none;
  color: var(--text-primary);
  transition: border-color 0.15s, box-shadow 0.15s;
}
.card:hover {
  border-color: var(--border-emphasis);
  box-shadow: 0 1px 0 rgba(255, 255, 255, 0.04);
}
.card-title { font-size: 18px; margin-bottom: 4px; font-family: var(--font-display, inherit); }
.card-sub { font-size: 13px; color: var(--text-muted); }
</style>
