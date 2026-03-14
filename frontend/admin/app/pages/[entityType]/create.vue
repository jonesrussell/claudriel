<script setup lang="ts">
import { useLanguage } from '~/composables/useLanguage'
import { useSchema } from '~/composables/useSchema'

const route = useRoute()
const router = useRouter()
const { t, entityLabel: translateEntityLabel } = useLanguage()
const { entityTypes } = useAuth()

const entityType = computed(() => route.params.entityType as string)
const { schema, fetch: fetchSchema } = useSchema(entityType.value)
const supportedType = computed(() => entityTypes.value.some((type) => type.id === entityType.value))
onMounted(() => fetchSchema())
const entityLabel = computed(() => translateEntityLabel(entityType.value, schema.value?.title ?? entityType.value))
const config = useRuntimeConfig()
useHead({ title: computed(() => `${t('create_entity', { type: entityLabel.value })} | ${config.public.appName}`) })
const successMessage = ref('')
const errorMessage = ref('')

function onSaved(resource: any) {
  successMessage.value = t('entity_created')
  setTimeout(() => {
    router.push(`/${entityType.value}/${resource.id}`)
  }, 500)
}

function onError(message: string) {
  errorMessage.value = message
}
</script>

<template>
  <div>
    <div v-if="!supportedType" class="error">{{ t('error_not_found') }}</div>
    <div class="page-header">
      <h1>{{ t('create_entity', { type: entityLabel }) }}</h1>
      <NuxtLink :to="`/${entityType}`" class="btn">
        {{ t('back_to_list') }}
      </NuxtLink>
    </div>

    <div v-if="successMessage" class="success">{{ successMessage }}</div>
    <div v-if="errorMessage" class="error">{{ errorMessage }}</div>

    <SchemaForm
      v-if="supportedType"
      :entity-type="entityType"
      @saved="onSaved"
      @error="onError"
    />
  </div>
</template>
