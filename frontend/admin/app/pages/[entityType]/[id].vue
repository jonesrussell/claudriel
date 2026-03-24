<script setup lang="ts">
import '~/components/entities'
import { useLanguage } from '~/composables/useLanguage'
import { useSchema } from '~/composables/useSchema'
import { useEntityDetailConfig } from '~/composables/useEntityDetailConfig'
import { useHostAdapter } from '~/host/useHostAdapter'

const route = useRoute()
const { t, entityLabel: translateEntityLabel } = useLanguage()
const { entityTypes } = useAuth()

const entityType = computed(() => route.params.entityType as string)
const { schema, fetch: fetchSchema } = useSchema(entityType.value)
const supportedType = computed(() => entityTypes.value.some((type) => type.id === entityType.value))
onMounted(() => fetchSchema())
const entityLabel = computed(() => translateEntityLabel(entityType.value, schema.value?.title ?? entityType.value))
const runtimeConfig = useRuntimeConfig()
useHead({ title: computed(() => `${t('edit_entity', { type: entityLabel.value })} | ${runtimeConfig.public.appName}`) })
const entityId = computed(() => route.params.id as string)
const successMessage = ref('')
const errorMessage = ref('')

// Check for relationship-aware config
const detailConfig = computed(() => useEntityDetailConfig(entityType.value))

// Fetch entity data for EntityDetailLayout
const host = useHostAdapter()
const entity = ref<Record<string, any> | null>(null)
const entityLoading = ref(false)

async function loadEntity() {
  if (!detailConfig.value) return
  entityLoading.value = true
  try {
    const resource = await host.transport.get(entityType.value, entityId.value)
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
    <EntityDetailLayout
      v-if="detailConfig && entity"
      :config="detailConfig"
      :entity="entity"
      :entity-type="entityType"
      @saved="onSaved"
      @error="onError"
    />

    <!-- Loading state for relationship layout -->
    <div v-else-if="detailConfig && entityLoading" class="loading">Loading...</div>

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
