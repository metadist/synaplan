<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { useAuthStore } from '@/stores/auth'

interface RetryModelOption {
  id: number
  label: string
}

const KNOWN_REASONS = [
  'schema_mismatch',
  'context_length_exceeded',
  'request_too_large',
  'rate_limited',
  'quota_exceeded',
  'auth_failed',
  'model_unavailable',
  'content_filtered',
  'timeout',
  'upstream_unavailable',
  'unknown',
] as const

type ErrorReason = (typeof KNOWN_REASONS)[number]

const props = defineProps<{
  errorReason: string
  canRetryModel?: boolean
  errorDebug?: string | null
  recommendedModelLabel?: string | null
  recommendedModelId?: number | null
  modelOptions?: RetryModelOption[]
}>()

const emit = defineEmits<{
  retry: [modelId?: number]
}>()

const { t } = useI18n()
const authStore = useAuthStore()
const detailsOpen = ref(false)

const reason = computed<ErrorReason>(() => {
  return (KNOWN_REASONS as readonly string[]).includes(props.errorReason)
    ? (props.errorReason as ErrorReason)
    : 'unknown'
})

const title = computed(() => t(`chatError.reason.${reason.value}.title`))
const body = computed(() => t(`chatError.reason.${reason.value}.body`))
const showRetry = computed(
  () =>
    props.canRetryModel !== false &&
    reason.value !== 'auth_failed' &&
    reason.value !== 'quota_exceeded'
)
const canSeeDebug = computed(() => authStore.isAdmin && !!props.errorDebug)
const pickedModelId = ref<number | undefined>(props.recommendedModelId ?? undefined)
const options = computed(() => props.modelOptions ?? [])
const showModelPicker = computed(() => showRetry.value && options.value.length > 1)
const pickedLabel = computed(() => {
  const picked = options.value.find((option) => option.id === pickedModelId.value)
  return picked?.label ?? props.recommendedModelLabel ?? null
})
const retryLabel = computed(() => {
  if (pickedLabel.value) {
    return t('chatError.retryWith', { model: pickedLabel.value })
  }
  return t('chatError.retry')
})

watch(
  () => props.recommendedModelId,
  (id) => {
    if (id != null) {
      pickedModelId.value = id
    }
  }
)

const onModelChange = (event: Event) => {
  const value = Number((event.target as HTMLSelectElement).value)
  pickedModelId.value = Number.isFinite(value) ? value : undefined
}

const retry = () => {
  emit('retry', pickedModelId.value ?? props.recommendedModelId ?? undefined)
}
</script>

<template>
  <div class="surface-card alert-error rounded-xl p-4 space-y-3" data-testid="chat-error-notice">
    <div class="flex items-start gap-3">
      <Icon icon="mdi:alert-circle-outline" class="w-5 h-5 alert-error-text flex-shrink-0 mt-0.5" />
      <div class="min-w-0 space-y-1">
        <h3 class="text-sm font-semibold txt-primary" data-testid="chat-error-title">
          {{ title }}
        </h3>
        <p class="text-sm txt-secondary" data-testid="chat-error-body">
          {{ body }}
        </p>
      </div>
    </div>

    <div v-if="showRetry" class="flex flex-wrap items-center gap-2">
      <label v-if="showModelPicker" class="sr-only" for="chat-error-model">
        {{ t('chatError.chooseModel') }}
      </label>
      <select
        v-if="showModelPicker"
        id="chat-error-model"
        :value="pickedModelId"
        class="px-3 py-2 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm max-w-xs focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
        data-testid="chat-error-model-select"
        @change="onModelChange"
      >
        <option v-for="option in options" :key="option.id" :value="option.id">
          {{ option.label }}
        </option>
      </select>
      <button
        type="button"
        class="btn-primary text-sm inline-flex items-center gap-2"
        data-testid="btn-chat-error-retry"
        @click="retry"
      >
        <Icon icon="mdi:refresh" class="w-4 h-4" />
        {{ retryLabel }}
      </button>
    </div>
    <p v-else class="text-sm txt-secondary" data-testid="chat-error-no-retry">
      {{ t('chatError.noRetryHint') }}
    </p>

    <div v-if="canSeeDebug" class="pt-2 border-t border-light-border/30 dark:border-dark-border/20">
      <button
        type="button"
        class="flex items-center gap-2 text-xs font-semibold txt-secondary hover:txt-primary"
        data-testid="btn-chat-error-details"
        @click="detailsOpen = !detailsOpen"
      >
        <Icon :icon="detailsOpen ? 'mdi:chevron-up' : 'mdi:chevron-down'" class="w-4 h-4" />
        {{ t('chatError.showDetails') }}
        <span
          class="text-[10px] uppercase font-bold px-2 py-0.5 rounded bg-red-500/10 text-red-600 dark:text-red-400 tracking-wider"
        >
          {{ t('error.adminOnly') }}
        </span>
      </button>
      <pre
        v-if="detailsOpen"
        class="mt-2 text-xs txt-secondary font-mono bg-black/5 dark:bg-white/5 p-3 rounded break-words whitespace-pre-wrap"
        data-testid="chat-error-debug"
        >{{ errorDebug }}</pre>
    </div>
  </div>
</template>
