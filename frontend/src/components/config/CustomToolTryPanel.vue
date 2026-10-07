<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  customToolsApi,
  customToolFieldClass,
  type CustomTool,
} from '@/services/api/customToolsApi'
import { useNotification } from '@/composables/useNotification'

const props = defineProps<{
  tool: CustomTool
}>()

const { t } = useI18n()
const { error: showError } = useNotification()
const inputJson = ref('{}')
const requestText = ref('')
const responseText = ref('')
const trying = ref(false)

const pretty = (value: unknown): string => {
  if (typeof value === 'string') {
    try {
      return JSON.stringify(JSON.parse(value), null, 2)
    } catch {
      return value
    }
  }
  return JSON.stringify(value, null, 2)
}

const run = async () => {
  trying.value = true
  requestText.value = ''
  responseText.value = ''
  try {
    const parsed = JSON.parse(inputJson.value) as Record<string, unknown>
    const result = await customToolsApi.try(props.tool.id, parsed)
    if (result.request) {
      requestText.value = pretty(result.request)
    }
    if (result.response) {
      responseText.value = pretty(result.response)
    } else if (result.sent === false) {
      responseText.value = t('customTools.tryNotSent')
    }
  } catch {
    showError(t('customTools.tryFailed'))
  } finally {
    trying.value = false
  }
}
</script>

<template>
  <div class="space-y-2" data-testid="custom-tool-try">
    <p class="text-sm txt-secondary">{{ $t('customTools.tryHint') }}</p>
    <textarea v-model="inputJson" rows="3" :class="customToolFieldClass" />
    <button
      type="button"
      class="btn-secondary px-4 py-2.5 rounded-xl text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
      :disabled="trying"
      @click="run"
    >
      {{ $t('customTools.try') }}
    </button>
    <div v-if="requestText" class="space-y-1">
      <p class="text-xs font-medium txt-primary">{{ $t('customTools.tryRequest') }}</p>
      <pre
        class="text-xs txt-secondary overflow-x-auto whitespace-pre-wrap"
        data-testid="custom-tool-try-request"
        >{{ requestText }}</pre>
    </div>
    <div v-if="responseText" class="space-y-1">
      <p class="text-xs font-medium txt-primary">{{ $t('customTools.tryResponse') }}</p>
      <pre
        class="text-xs txt-secondary overflow-x-auto whitespace-pre-wrap"
        data-testid="custom-tool-try-response"
        >{{ responseText }}</pre>
    </div>
  </div>
</template>
