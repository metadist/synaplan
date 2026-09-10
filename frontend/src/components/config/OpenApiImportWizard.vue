<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  customToolsApi,
  customToolFieldClass,
  type OpenApiOperation,
} from '@/services/api/customToolsApi'
import { useNotification } from '@/composables/useNotification'

const emit = defineEmits<{
  close: []
  applied: []
}>()

const { t } = useI18n()
const { success, error: showError } = useNotification()
const url = ref('')
const documentText = ref('')
const baseUrl = ref('')
const operations = ref<OpenApiOperation[]>([])
const selected = ref<Record<string, boolean>>({})
const loading = ref(false)

const classLabel = (sideEffect: string): string => {
  if (sideEffect === 'read') return t('customTools.classRead')
  if (sideEffect === 'destructive') return t('customTools.classDestructive')
  return t('customTools.classWrite')
}

const preview = async () => {
  loading.value = true
  try {
    const data = await customToolsApi.previewOpenApi({
      url: url.value || undefined,
      document: documentText.value || undefined,
    })
    operations.value = data.operations
    selected.value = Object.fromEntries(data.operations.map((op) => [op.operationId, true]))
  } catch {
    showError(t('customTools.importFailed'))
  } finally {
    loading.value = false
  }
}

const apply = async () => {
  const chosen = operations.value.filter((op) => selected.value[op.operationId])
  loading.value = true
  try {
    await customToolsApi.applyOpenApi(chosen, baseUrl.value, null)
    success(t('customTools.imported'))
    emit('applied')
  } catch {
    showError(t('customTools.importFailed'))
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="surface-card p-4 space-y-3" data-testid="openapi-import-wizard">
    <h3 class="font-semibold txt-primary">{{ $t('customTools.import') }}</h3>
    <label class="block text-sm txt-primary">
      {{ $t('customTools.importUrl') }}
      <input v-model="url" :class="customToolFieldClass" />
    </label>
    <label class="block text-sm txt-primary">
      {{ $t('customTools.importDocument') }}
      <textarea v-model="documentText" rows="6" :class="customToolFieldClass" />
    </label>
    <label class="block text-sm txt-primary">
      {{ $t('customTools.baseUrl') }}
      <input v-model="baseUrl" :class="customToolFieldClass" />
    </label>
    <button
      type="button"
      class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
      :disabled="loading"
      @click="preview"
    >
      {{ $t('customTools.preview') }}
    </button>
    <ul v-if="operations.length" class="space-y-2">
      <li v-for="op in operations" :key="op.operationId" class="flex items-center gap-2 text-sm">
        <input v-model="selected[op.operationId]" type="checkbox" class="accent-[var(--brand)]" />
        <span class="txt-primary">{{ op.method }} {{ op.path }}</span>
        <span class="txt-secondary">{{ classLabel(op.sideEffect) }}</span>
      </li>
    </ul>
    <div class="flex flex-wrap gap-2">
      <button
        type="button"
        class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
        :disabled="loading || operations.length === 0"
        @click="apply"
      >
        {{ $t('customTools.applyImport') }}
      </button>
      <button
        type="button"
        class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
        @click="emit('close')"
      >
        {{ $t('customTools.cancel') }}
      </button>
    </div>
  </div>
</template>
