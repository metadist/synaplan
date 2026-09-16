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
const output = ref('')
const trying = ref(false)

const run = async () => {
  trying.value = true
  try {
    const parsed = JSON.parse(inputJson.value) as Record<string, unknown>
    const result = await customToolsApi.try(props.tool.id, parsed)
    output.value = JSON.stringify(result, null, 2)
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
      class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
      :disabled="trying"
      @click="run"
    >
      {{ $t('customTools.try') }}
    </button>
    <pre v-if="output" class="text-xs txt-secondary overflow-x-auto">{{ output }}</pre>
  </div>
</template>
