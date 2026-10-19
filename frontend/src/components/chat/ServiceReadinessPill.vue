<template>
  <RouterLink
    v-if="summary"
    to="/setup/status"
    class="inline-flex items-center gap-1.5 h-10 px-3 rounded-full shadow-lg text-xs font-semibold active:scale-95 transition-transform whitespace-nowrap"
    :class="
      summary.kind === 'attention'
        ? 'bg-[var(--status-warning-muted)] text-[var(--status-warning-text)]'
        : 'surface-card txt-primary'
    "
    :aria-label="summary.label"
    :title="summary.label"
    :data-kind="summary.kind"
    data-testid="pill-service-readiness"
  >
    <ExclamationTriangleIcon
      v-if="summary.kind === 'attention'"
      class="w-4 h-4 shrink-0"
      aria-hidden="true"
    />
    <ArrowPathIcon v-else class="w-4 h-4 shrink-0 animate-spin txt-brand" aria-hidden="true" />
    <span class="max-[360px]:hidden" data-testid="pill-service-readiness-label">{{
      summary.label
    }}</span>
    <span class="hidden max-[360px]:inline" data-testid="pill-service-readiness-short">{{
      summary.shortLabel
    }}</span>
  </RouterLink>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ArrowPathIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/outline'
import { useTour } from '@/composables/useTour'
import { useAuthStore } from '@/stores/auth'
import { useServiceReadinessStore } from '@/stores/serviceReadiness'
import { SERVICES_READY_TOAST_SEEN_ID, SERVICES_STARTING_TOAST_SEEN_ID } from '@/tours'
import { serviceReadinessPillSummary } from '@/utils/serviceReadiness'

/**
 * Quiet onboarding pill next to the model-mix button while optional services
 * are starting, downloading or need attention (plan §5.10, C15).
 */
const { t } = useI18n()
const authStore = useAuthStore()
const readinessStore = useServiceReadinessStore()
const { seen } = useTour()

const progressSeen = computed(
  () =>
    !readinessStore.sawInProgressThisSession &&
    seen.value.includes(SERVICES_READY_TOAST_SEEN_ID) &&
    !seen.value.includes(SERVICES_STARTING_TOAST_SEEN_ID)
)

const summary = computed(() => {
  if (!authStore.isAuthenticated || !authStore.isAdmin) return null
  return serviceReadinessPillSummary(
    readinessStore.services,
    (key, params, plural) =>
      plural === undefined ? t(key, params ?? {}) : t(key, params ?? {}, plural),
    progressSeen.value
  )
})

onMounted(() => {
  if (authStore.isAuthenticated && authStore.isAdmin) {
    readinessStore.acquire()
  }
})

onUnmounted(() => {
  readinessStore.release()
})
</script>
