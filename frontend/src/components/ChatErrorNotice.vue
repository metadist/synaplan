<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { useAuthStore } from '@/stores/auth'

interface RetryModelOption {
  id: number
  label: string
}

/**
 * Control strip below a failed assistant turn. The explanation itself is the
 * message body the backend localized and persisted (`ai_errors` catalog) — this
 * component only frames it and offers the retry, so the sentence exists once.
 */
const props = defineProps<{
  canRetryModel?: boolean
  errorDebug?: string | null
  recommendedModelId?: number | null
  failedModelId?: number | null
  modelOptions?: RetryModelOption[]
}>()

const emit = defineEmits<{
  retry: [modelId?: number]
}>()

const { t } = useI18n()
const authStore = useAuthStore()
const detailsOpen = ref(false)

const showRetry = computed(() => props.canRetryModel !== false)
const canSeeDebug = computed(() => authStore.isAdmin && !!props.errorDebug)

// Retrying on the model that just failed reproduces the same error, so it is
// never offered.
const retryOptions = computed(() =>
  (props.modelOptions ?? []).filter((option) => option.id !== props.failedModelId)
)
const showModelPicker = computed(() => showRetry.value && retryOptions.value.length > 1)

const defaultRetryId = computed(() => {
  const recommended = props.recommendedModelId ?? null
  if (recommended !== null && recommended !== props.failedModelId) {
    return recommended
  }
  return retryOptions.value[0]?.id
})

const pickedModelId = ref<number | undefined>(defaultRetryId.value)
watch(defaultRetryId, (id) => {
  pickedModelId.value = id
})

const pickedLabel = computed(
  () => retryOptions.value.find((option) => option.id === pickedModelId.value)?.label ?? null
)
const retryLabel = computed(() =>
  pickedLabel.value ? t('chatError.retryWith', { model: pickedLabel.value }) : t('chatError.retry')
)

const retry = () => {
  emit('retry', pickedModelId.value)
}
</script>

<template>
  <div class="alert-error space-y-3" data-testid="chat-error-notice">
    <div class="flex items-center gap-2">
      <Icon icon="mdi:alert-circle-outline" class="w-5 h-5 alert-error-text flex-shrink-0" />
      <h3 class="text-sm font-semibold alert-error-text" data-testid="chat-error-title">
        {{ t('chatError.title') }}
      </h3>
    </div>

    <div v-if="showRetry" class="flex flex-wrap items-center gap-2">
      <select
        v-if="showModelPicker"
        v-model="pickedModelId"
        class="pill text-xs max-w-xs cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]"
        :aria-label="t('chatError.chooseModel')"
        data-testid="chat-error-model-select"
      >
        <option v-for="option in retryOptions" :key="option.id" :value="option.id">
          {{ option.label }}
        </option>
      </select>
      <button
        type="button"
        class="pill text-xs font-medium"
        data-testid="btn-chat-error-retry"
        @click="retry"
      >
        <Icon icon="mdi:refresh" class="w-4 h-4" />
        <span>{{ retryLabel }}</span>
      </button>
    </div>

    <div v-if="canSeeDebug" class="pt-2 border-t border-light-border/30 dark:border-dark-border/20">
      <button
        type="button"
        class="flex items-center gap-2 text-xs font-semibold alert-error-text hover:opacity-80"
        data-testid="btn-chat-error-details"
        @click="detailsOpen = !detailsOpen"
      >
        <Icon :icon="detailsOpen ? 'mdi:chevron-up' : 'mdi:chevron-down'" class="w-4 h-4" />
        {{ t('chatError.showDetails') }}
        <span
          class="text-[10px] uppercase font-bold px-2 py-0.5 rounded bg-black/5 dark:bg-white/10 alert-error-text tracking-wider"
        >
          {{ t('error.adminOnly') }}
        </span>
      </button>
      <pre
        v-if="detailsOpen"
        class="mt-2 text-xs txt-primary font-mono bg-black/5 dark:bg-white/5 p-3 rounded break-words whitespace-pre-wrap"
        data-testid="chat-error-debug"
        >{{ errorDebug }}</pre>
    </div>
  </div>
</template>
