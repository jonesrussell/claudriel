<script setup lang="ts">
import { useLanguage } from '~/composables/useLanguage'
import { useSchema } from '~/composables/useSchema'

const route = useRoute()
const { t, entityLabel: translateEntityLabel } = useLanguage()
const { entityTypes } = useAuth()

const entityType = computed(() => route.params.entityType as string)
const { schema, loading, error, fetch: fetchSchema } = useSchema(entityType.value)
const supportedType = computed(() => entityTypes.value.some((type) => type.id === entityType.value))

onMounted(async () => {
  await fetchSchema()
})
const entityLabel = computed(() => translateEntityLabel(entityType.value, schema.value?.title ?? entityType.value))
const config = useRuntimeConfig()
useHead({ title: computed(() => `${entityLabel.value} | ${config.public.appName}`) })
</script>

<template>
  <div>
    <template v-if="!supportedType || (!loading && error)">
      <div class="page-header">
        <h1>{{ t('error_not_found') }}</h1>
      </div>
      <p class="error">{{ error ?? t('error_loading_types') }}</p>
      <NuxtLink to="/" class="btn">← {{ t('dashboard') }}</NuxtLink>
    </template>

    <template v-else>
      <div class="page-header">
        <h1>{{ entityLabel }}</h1>
        <div class="page-actions">
          <NuxtLink :to="`/${entityType}/create`" class="btn btn-primary">
            {{ t('create_new') }}
          </NuxtLink>
        </div>
      </div>

      <SchemaList :entity-type="entityType" />
    </template>
  </div>
</template>

<style scoped>
.page-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}
</style>
