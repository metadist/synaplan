<template>
  <!--
    When realtime is disabled by the operator/admin (REALTIME_ENABLED=false)
    we hide the badge entirely — the dashboard already degrades to its REST
    endpoints and the user does not need a permanent reminder that a feature
    flag is off. If you DO want a "Disabled" pill in your view, set the
    `showWhenDisabled` prop.
  -->
  <div
    v-if="state !== 'disabled' || showWhenDisabled"
    class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium select-none"
    :class="badgeClass"
    :title="tooltip"
    role="status"
    :aria-live="state === 'connected' ? 'off' : 'polite'"
    data-testid="comp-realtime-status"
  >
    <span class="relative flex h-2 w-2">
      <span
        v-if="isPulsing"
        class="animate-ping absolute inline-flex h-full w-full rounded-full opacity-75"
        :class="dotClass"
      ></span>
      <span class="relative inline-flex rounded-full h-2 w-2" :class="dotClass"></span>
    </span>
    <span>{{ label }}</span>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRealtimeStore } from '@/stores/realtime'

const props = withDefaults(
  defineProps<{
    /** Hide the badge when the realtime client is fully healthy. Useful in compact toolbars. */
    hideWhenConnected?: boolean
    /**
     * Show a "Disabled" pill when REALTIME_ENABLED=false. Off by default
     * because the dashboard transparently falls back to REST in that case.
     */
    showWhenDisabled?: boolean
  }>(),
  { hideWhenConnected: false, showWhenDisabled: false }
)

const store = useRealtimeStore()
const { t } = useI18n()

const state = computed(() => store.state)
const isPulsing = computed(() => state.value === 'connecting' || state.value === 'reconnecting')

const label = computed(() => t(`realtime.status.${state.value}`))

const tooltip = computed(() => {
  if (state.value === 'error') {
    return t('realtime.tooltip.error', { message: store.lastError ?? 'unknown' })
  }
  return t(`realtime.tooltip.${state.value}`)
})

// Theme-aware status colors from style.css (light + dark handled by the vars).
const badgeClass = computed(() => {
  if (props.hideWhenConnected && state.value === 'connected') return 'sr-only'
  switch (state.value) {
    case 'connected':
      return 'bg-[var(--status-success-muted)] text-[var(--status-success-text)]'
    case 'connecting':
    case 'reconnecting':
      return 'bg-[var(--status-warning-muted)] text-[var(--status-warning-text)]'
    case 'error':
      return 'bg-[var(--status-error-muted)] text-[var(--status-error-text)]'
    case 'disabled':
    default:
      return 'bg-[var(--status-neutral-muted)] text-[var(--status-neutral-text)]'
  }
})

const dotClass = computed(() => {
  switch (state.value) {
    case 'connected':
      return 'bg-[var(--status-success)]'
    case 'connecting':
    case 'reconnecting':
      return 'bg-[var(--status-warning)]'
    case 'error':
      return 'bg-[var(--status-error)]'
    case 'disabled':
    default:
      return 'bg-[var(--status-neutral)]'
  }
})
</script>
