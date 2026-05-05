<template>
  <Teleport to="#app">
    <Transition name="modal">
      <div
        v-if="open"
        class="fixed inset-0 z-50 flex items-center justify-center p-4"
        data-testid="modal-embedding-switch"
      >
        <div
          class="absolute inset-0 bg-black/50 backdrop-blur-sm"
          data-testid="modal-backdrop"
          @click="onCancel"
        ></div>

        <div
          class="relative surface-elevated max-w-2xl w-full p-6 animate-in max-h-[90vh] overflow-y-auto"
          data-testid="modal-body"
        >
          <!-- Header -->
          <div class="flex items-start justify-between mb-4">
            <div class="flex items-center gap-3">
              <div
                class="w-12 h-12 rounded-xl flex items-center justify-center flex-shrink-0"
                :class="iconBackgroundClass"
              >
                <ArrowPathIcon class="w-6 h-6" :class="iconColorClass" />
              </div>
              <div>
                <h2 class="text-xl font-bold txt-primary">
                  {{ $t('config.embeddingSwitch.title') }}
                </h2>
                <p class="text-sm txt-secondary">{{ $t('config.embeddingSwitch.subtitle') }}</p>
              </div>
            </div>
            <button
              type="button"
              class="txt-secondary hover:txt-primary transition-colors p-1"
              data-testid="btn-close"
              @click="onCancel"
            >
              <XMarkIcon class="w-5 h-5" />
            </button>
          </div>

          <!-- Premium gate: short-circuit when not allowed -->
          <div
            v-if="guardReason === 'requires_premium'"
            class="rounded-xl border-2 border-amber-500/30 bg-amber-500/10 p-5 mb-4"
            data-testid="banner-premium-required"
          >
            <div class="flex items-start gap-3">
              <LockClosedIcon class="w-6 h-6 text-amber-500 flex-shrink-0 mt-0.5" />
              <div class="flex-1">
                <h3 class="font-semibold txt-primary mb-1">
                  {{ $t('config.embeddingSwitch.premium.title') }}
                </h3>
                <p class="text-sm txt-secondary mb-3">
                  {{
                    $t('config.embeddingSwitch.premium.body', {
                      level: guardCurrentLevel,
                    })
                  }}
                </p>
                <a
                  href="/profile?tab=subscription"
                  class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-amber-500 hover:bg-amber-600 text-white text-sm font-semibold transition-colors"
                  data-testid="btn-upgrade-cta"
                >
                  {{ $t('config.embeddingSwitch.premium.upgradeCta') }}
                  <ArrowRightIcon class="w-4 h-4" />
                </a>
              </div>
            </div>
          </div>

          <!-- Cooldown gate -->
          <div
            v-else-if="guardReason === 'cooldown_active'"
            class="rounded-xl border-2 border-blue-500/30 bg-blue-500/10 p-5 mb-4"
            data-testid="banner-cooldown"
          >
            <div class="flex items-start gap-3">
              <ClockIcon class="w-6 h-6 text-blue-500 flex-shrink-0 mt-0.5" />
              <div class="flex-1">
                <h3 class="font-semibold txt-primary mb-1">
                  {{ $t('config.embeddingSwitch.cooldown.title') }}
                </h3>
                <p class="text-sm txt-secondary">
                  {{
                    $t('config.embeddingSwitch.cooldown.body', {
                      minutes: cooldownMinutes,
                    })
                  }}
                </p>
              </div>
            </div>
          </div>

          <!-- Standard switch flow -->
          <template v-else>
            <!-- Model Switch Summary -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4">
              <div class="surface-card p-3">
                <p class="text-xs uppercase tracking-wide txt-secondary mb-1">
                  {{ $t('config.embeddingSwitch.fromLabel') }}
                </p>
                <p class="font-semibold txt-primary text-sm truncate">
                  {{ estimate?.fromModel.model || $t('config.embeddingSwitch.unknown') }}
                </p>
                <p class="text-xs txt-secondary">{{ estimate?.fromModel.provider || '—' }}</p>
              </div>
              <div class="surface-card p-3 ring-2 ring-[var(--brand)]/40">
                <p class="text-xs uppercase tracking-wide txt-secondary mb-1">
                  {{ $t('config.embeddingSwitch.toLabel') }}
                </p>
                <p class="font-semibold txt-primary text-sm truncate">
                  {{ targetModelName }}
                </p>
                <p class="text-xs txt-secondary">{{ targetModelProvider }}</p>
              </div>
            </div>

            <!-- Loading state -->
            <div v-if="loading" class="text-center py-8" data-testid="section-loading">
              <div
                class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-[var(--brand)]"
              ></div>
              <p class="mt-2 txt-secondary text-sm">
                {{ $t('config.embeddingSwitch.estimating') }}
              </p>
            </div>

            <!-- Severity Banner -->
            <div
              v-else-if="estimate"
              class="rounded-xl border-2 p-4 mb-4"
              :class="severityBannerClasses"
              data-testid="banner-severity"
            >
              <div class="flex items-start gap-3">
                <component
                  :is="severityIcon"
                  class="w-6 h-6 flex-shrink-0 mt-0.5"
                  :class="severityIconColor"
                />
                <div class="flex-1">
                  <h3 class="font-semibold txt-primary mb-1">
                    {{ $t(`config.embeddingSwitch.severity.${estimate.severity}.title`) }}
                  </h3>
                  <p class="text-sm txt-secondary">
                    {{ $t(`config.embeddingSwitch.severity.${estimate.severity}.body`) }}
                  </p>
                </div>
              </div>
            </div>

            <!-- Per-Scope Breakdown -->
            <div v-if="estimate" class="surface-card p-4 mb-4" data-testid="section-breakdown">
              <h3
                class="text-sm font-semibold txt-primary mb-3 uppercase tracking-wide flex items-center gap-2"
              >
                <ChartBarIcon class="w-4 h-4 text-[var(--brand)]" />
                {{ $t('config.embeddingSwitch.breakdown.title') }}
              </h3>
              <div class="space-y-2">
                <div
                  v-for="(value, key) in estimate.scopes"
                  :key="key"
                  class="flex items-center justify-between text-sm py-2 border-b border-light-border/20 dark:border-dark-border/20 last:border-0"
                >
                  <div class="flex items-center gap-2">
                    <component
                      :is="scopeIcons[key as keyof typeof scopeIcons]"
                      class="w-4 h-4 txt-secondary"
                    />
                    <span class="txt-primary capitalize">
                      {{ $t(`config.embeddingSwitch.scopes.${key}`) }}
                    </span>
                  </div>
                  <div class="flex items-center gap-3 text-xs">
                    <span class="txt-secondary"
                      >{{ value.chunks.toLocaleString() }}
                      {{ $t('config.embeddingSwitch.chunks') }}</span
                    >
                    <span class="txt-secondary">·</span>
                    <span class="txt-secondary"
                      >~{{ formatTokens(value.tokensEstimated) }}
                      {{ $t('config.embeddingSwitch.tokens') }}</span
                    >
                    <span class="txt-secondary">·</span>
                    <span class="font-mono font-semibold txt-primary"
                      >${{ value.costEstimatedUsd.toFixed(4) }}</span
                    >
                  </div>
                </div>
              </div>

              <!-- Total -->
              <div
                class="mt-3 pt-3 border-t-2 border-light-border/30 dark:border-dark-border/20 flex items-center justify-between text-sm font-semibold"
              >
                <span class="txt-primary">{{ $t('config.embeddingSwitch.total') }}</span>
                <div class="flex items-center gap-3">
                  <span class="txt-primary">{{ estimate.totals.chunks.toLocaleString() }}</span>
                  <span class="txt-secondary">·</span>
                  <span class="txt-primary"
                    >~{{ formatTokens(estimate.totals.tokensEstimated) }}</span
                  >
                  <span class="txt-secondary">·</span>
                  <span class="font-mono text-base txt-brand"
                    >${{ estimate.totals.costEstimatedUsd.toFixed(4) }}</span
                  >
                </div>
              </div>
            </div>

            <!-- Critical confirmation -->
            <label
              v-if="estimate?.severity === 'critical'"
              class="flex items-start gap-3 p-3 rounded-lg surface-card border border-red-500/40 mb-4 cursor-pointer"
              data-testid="checkbox-confirm-critical"
            >
              <input
                v-model="confirmCritical"
                type="checkbox"
                class="mt-1 w-4 h-4 rounded border-red-500/50 text-red-500 focus:ring-red-500"
              />
              <span class="text-sm txt-primary">
                {{ $t('config.embeddingSwitch.confirmCritical') }}
              </span>
            </label>

            <!-- Actions -->
            <div
              class="flex flex-col-reverse sm:flex-row items-stretch sm:items-center justify-end gap-3"
            >
              <button
                type="button"
                class="px-4 py-2.5 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-secondary hover:txt-primary hover:bg-black/5 dark:hover:bg-white/5 text-sm font-medium transition-colors"
                data-testid="btn-cancel"
                @click="onCancel"
              >
                {{ $t('config.embeddingSwitch.cancel') }}
              </button>
              <button
                type="button"
                class="px-5 py-2.5 rounded-lg text-white text-sm font-semibold transition-colors flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
                :class="confirmButtonClass"
                :disabled="!canSubmit"
                data-testid="btn-confirm-switch"
                @click="onConfirm"
              >
                <span
                  v-if="submitting"
                  class="inline-block w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin"
                ></span>
                <ArrowPathIcon v-else class="w-4 h-4" />
                {{ $t('config.embeddingSwitch.confirm') }}
              </button>
            </div>
          </template>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  ArrowPathIcon,
  ArrowRightIcon,
  ChartBarIcon,
  ClockIcon,
  ExclamationTriangleIcon,
  ExclamationCircleIcon,
  InformationCircleIcon,
  LockClosedIcon,
  XMarkIcon,
  DocumentTextIcon,
  CpuChipIcon,
  Square2StackIcon,
} from '@heroicons/vue/24/outline'
import { adminEmbeddingApi, type EmbeddingCostEstimate } from '@/services/api/adminEmbeddingApi'
import { useNotification } from '@/composables/useNotification'

const props = defineProps<{
  open: boolean
  toModelId: number | null
  targetModelName: string
  targetModelProvider: string
  guardReason: 'requires_premium' | 'cooldown_active' | null
  guardCurrentLevel: string
  cooldownEndsAt: number | null
}>()

const emit = defineEmits<{
  (e: 'cancel'): void
  (e: 'switched', runId: number): void
}>()

const { t } = useI18n()
const { error: showError } = useNotification()

const loading = ref(false)
const submitting = ref(false)
const estimate = ref<EmbeddingCostEstimate | null>(null)
const confirmCritical = ref(false)

const cooldownMinutes = computed(() => {
  if (!props.cooldownEndsAt) return 0
  return Math.max(0, Math.ceil((props.cooldownEndsAt - Date.now() / 1000) / 60))
})

const scopeIcons = {
  documents: DocumentTextIcon,
  memories: Square2StackIcon,
  synapse: CpuChipIcon,
}

const severityIcon = computed(() => {
  switch (estimate.value?.severity) {
    case 'critical':
      return ExclamationCircleIcon
    case 'warning':
      return ExclamationTriangleIcon
    default:
      return InformationCircleIcon
  }
})

const severityIconColor = computed(() => {
  switch (estimate.value?.severity) {
    case 'critical':
      return 'text-red-500'
    case 'warning':
      return 'text-amber-500'
    default:
      return 'text-emerald-500'
  }
})

const severityBannerClasses = computed(() => {
  switch (estimate.value?.severity) {
    case 'critical':
      return 'border-red-500/40 bg-red-500/10'
    case 'warning':
      return 'border-amber-500/40 bg-amber-500/10'
    default:
      return 'border-emerald-500/40 bg-emerald-500/10'
  }
})

const iconBackgroundClass = computed(() => {
  if (props.guardReason === 'requires_premium') return 'bg-amber-500/10'
  if (props.guardReason === 'cooldown_active') return 'bg-blue-500/10'
  return 'bg-[var(--brand)]/10'
})

const iconColorClass = computed(() => {
  if (props.guardReason === 'requires_premium') return 'text-amber-500'
  if (props.guardReason === 'cooldown_active') return 'text-blue-500'
  return 'text-[var(--brand)]'
})

const confirmButtonClass = computed(() => {
  switch (estimate.value?.severity) {
    case 'critical':
      return 'bg-red-600 hover:bg-red-700'
    case 'warning':
      return 'bg-amber-600 hover:bg-amber-700'
    default:
      return 'bg-[var(--brand)] hover:bg-[var(--brand)]/90'
  }
})

const canSubmit = computed(() => {
  if (loading.value || submitting.value) return false
  if (!estimate.value) return false
  if (estimate.value.severity === 'critical' && !confirmCritical.value) return false
  return true
})

const formatTokens = (tokens: number): string => {
  if (tokens >= 1_000_000) return `${(tokens / 1_000_000).toFixed(2)}M`
  if (tokens >= 1_000) return `${(tokens / 1_000).toFixed(1)}k`
  return String(tokens)
}

const loadEstimate = async (modelId: number) => {
  loading.value = true
  estimate.value = null
  confirmCritical.value = false

  try {
    estimate.value = await adminEmbeddingApi.costEstimate(modelId)
  } catch (err) {
    console.error('Failed to load cost estimate:', err)
    showError(t('config.embeddingSwitch.estimateError'))
  } finally {
    loading.value = false
  }
}

const onCancel = () => {
  if (submitting.value) return
  emit('cancel')
}

const onConfirm = async () => {
  if (!estimate.value || !props.toModelId) return

  submitting.value = true
  try {
    const response = await adminEmbeddingApi.switch({
      toModelId: props.toModelId,
      scope: 'all',
      confirmCritical: estimate.value.severity === 'critical' ? true : undefined,
    })
    emit('switched', response.runId)
  } catch (err) {
    console.error('Switch failed:', err)
    showError(t('config.embeddingSwitch.switchError'))
  } finally {
    submitting.value = false
  }
}

watch(
  () => [props.open, props.toModelId] as const,
  async ([open, modelId]) => {
    if (!open || modelId === null || props.guardReason !== null) {
      estimate.value = null
      confirmCritical.value = false
      return
    }
    await loadEstimate(modelId)
  },
  { immediate: true }
)
</script>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.2s ease;
}
.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}
.animate-in {
  animation: scale-in 0.18s ease-out;
}
@keyframes scale-in {
  from {
    transform: scale(0.96);
    opacity: 0;
  }
  to {
    transform: scale(1);
    opacity: 1;
  }
}
</style>
