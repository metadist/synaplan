<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { useAuthStore } from '@/stores/auth'
import { chatErrorReasonKey, chatErrorSuggestsOtherModel } from '@/utils/chatErrorDisplay'

interface RetryModelOption {
  id: number
  label: string
}

/**
 * Control strip below a failed assistant turn. Shows a user-safe reason
 * (never provider internals) and a recovery that prefers a *different* model
 * than the one that just failed.
 */
const props = defineProps<{
  errorReason?: string | null
  errorMessage?: string | null
  hasPartialAnswer?: boolean
  canRetryModel?: boolean
  errorDebug?: string | null
  recommendedModelId?: number | null
  failedModelId?: number | null
  modelOptions?: RetryModelOption[]
}>()

const emit = defineEmits<{
  retry: [modelId?: number]
}>()

const { t, te } = useI18n()
const authStore = useAuthStore()
const detailsOpen = ref(false)

const suggestsOtherModel = computed(() => chatErrorSuggestsOtherModel(props.errorReason))
const showRetry = computed(() => {
  if (!suggestsOtherModel.value) {
    return false
  }
  return props.canRetryModel !== false
})
const canSeeDebug = computed(() => authStore.isAdmin && !!props.errorDebug)

const explanation = computed(() => {
  const streamed = props.errorMessage?.trim() ?? ''
  if (streamed !== '') {
    return streamed
  }
  const key = chatErrorReasonKey(props.errorReason)
  return te(key) ? t(key) : t('chatError.reason.unknown')
})

const showPartialDraft = computed(() => props.hasPartialAnswer === true)

// Retrying on the model that just failed reproduces the same error, so it is
// never the default pick. It is only offered when no other model exists.
const retryOptions = computed(() =>
  (props.modelOptions ?? []).filter((option) => option.id !== props.failedModelId)
)
const onlySameModel = computed(
  () => showRetry.value && retryOptions.value.length === 0 && (props.modelOptions ?? []).length > 0
)
const showModelPicker = computed(() => showRetry.value && retryOptions.value.length > 1)

const defaultRetryId = computed(() => {
  const recommended = props.recommendedModelId ?? null
  if (recommended !== null && recommended !== props.failedModelId) {
    return recommended
  }
  if (retryOptions.value[0]) {
    return retryOptions.value[0].id
  }
  if (onlySameModel.value) {
    return props.failedModelId ?? props.modelOptions?.[0]?.id
  }
  return undefined
})

const pickedModelId = ref<number | undefined>(defaultRetryId.value)
watch(defaultRetryId, (id) => {
  pickedModelId.value = id
})

const pickedLabel = computed(() => {
  const fromOthers = retryOptions.value.find((option) => option.id === pickedModelId.value)
  if (fromOthers) {
    return fromOthers.label
  }
  if (onlySameModel.value) {
    return (
      (props.modelOptions ?? []).find((option) => option.id === pickedModelId.value)?.label ?? null
    )
  }
  return null
})

const retryLabel = computed(() => {
  if (onlySameModel.value) {
    return t('chatError.retrySame')
  }
  return pickedLabel.value
    ? t('chatError.retryWith', { model: pickedLabel.value })
    : t('chatError.retry')
})

const retry = () => {
  emit('retry', pickedModelId.value)
}
</script>

<template>
  <div class="alert-error space-y-3" data-testid="chat-error-notice">
    <div class="flex items-start gap-2">
      <Icon icon="mdi:alert-circle-outline" class="w-5 h-5 alert-error-text flex-shrink-0 mt-0.5" />
      <div class="space-y-2 min-w-0">
        <h3 class="text-sm font-semibold alert-error-text" data-testid="chat-error-title">
          {{ t('chatError.title') }}
        </h3>
        <p class="text-sm txt-primary" data-testid="chat-error-body">
          {{ explanation }}
        </p>
        <p v-if="showPartialDraft" class="text-sm txt-secondary" data-testid="chat-error-partial">
          {{ t('chatError.partialDraft') }}
        </p>
        <p v-if="onlySameModel" class="text-sm txt-secondary" data-testid="chat-error-same-model">
          {{ t('chatError.retrySameHint') }}
        </p>
        <p v-else-if="!showRetry" class="text-sm txt-secondary" data-testid="chat-error-no-retry">
          {{ t('chatError.noRetry') }}
        </p>
      </div>
    </div>

    <div v-if="showRetry" class="flex flex-wrap items-center gap-2">
      <select
        v-if="showModelPicker"
        v-model="pickedModelId"
        class="px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm max-w-xs cursor-pointer focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
        :aria-label="t('chatError.chooseModel')"
        data-testid="chat-error-model-select"
      >
        <option v-for="option in retryOptions" :key="option.id" :value="option.id">
          {{ option.label }}
        </option>
      </select>
      <button
        type="button"
        class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium inline-flex items-center gap-2"
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
