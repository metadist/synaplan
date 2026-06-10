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

const badgeClass = computed(() => {
  if (props.hideWhenConnected && state.value === 'connected') return 'sr-only'
  switch (state.value) {
    case 'connected':
      return 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300'
    case 'connecting':
    case 'reconnecting':
      return 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300'
    case 'error':
      return 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-300'
    case 'disabled':
    default:
      return 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'
  }
})

const dotClass = computed(() => {
  switch (state.value) {
    case 'connected':
      return 'bg-emerald-500'
    case 'connecting':
    case 'reconnecting':
      return 'bg-amber-500'
    case 'error':
      return 'bg-red-500'
    case 'disabled':
    default:
      return 'bg-slate-400'
  }
})
</script>
