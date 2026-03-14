<script setup lang="ts">
import { useLanguage } from '~/composables/useLanguage'

const { t, entityLabel } = useLanguage()
const config = useRuntimeConfig()
useHead({ title: computed(() => `${t('dashboard')} | ${config.public.appName}`) })
const { entityTypes } = useAuth()
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
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: 8px;
  padding: 20px;
  text-decoration: none;
  color: var(--color-text);
  transition: border-color 0.15s;
}
.card:hover { border-color: var(--color-primary); }
.card-title { font-size: 18px; margin-bottom: 4px; }
.card-sub { font-size: 13px; color: var(--color-muted); }
</style>
