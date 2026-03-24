<script setup lang="ts">
import { computed, onMounted } from 'vue'
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
  const hidden = ['uuid', 'created_at', 'updated_at', 'tenant_id', 'account_id']
  return Object.keys(items.value[0].attributes)
    .filter((k) => !hidden.includes(k))
    .slice(0, 4)
})
</script>

<template>
  <div class="relationship-panel">
    <div class="panel-header">
      <h3>{{ targetType }}</h3>
      <button data-testid="link-btn" class="btn btn-sm" @click="emit('link')">+ Link</button>
    </div>

    <div v-if="loading" class="panel-loading">Loading...</div>

    <div v-else-if="error" class="panel-error">
      <span>{{ error }}</span>
      <button class="btn btn-sm" @click="fetchItems()">Retry</button>
    </div>

    <div v-else-if="items.length === 0" class="panel-empty">
      No linked {{ targetType }} entities.
    </div>

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
            <NuxtLink
              v-if="col === 'name' || col === 'title'"
              :to="`/${targetType}/${item.id}`"
            >
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
