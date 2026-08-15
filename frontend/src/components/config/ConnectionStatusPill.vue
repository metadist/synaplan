<script setup lang="ts">
const props = defineProps<{
  status: string
}>()

const tone: Record<string, string> = {
  connected: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
  error: 'bg-red-500/15 text-red-700 dark:text-red-300',
  reauth_required: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
  never_tested: 'bg-[var(--brand)]/10 text-[var(--brand)]',
  disconnected: 'surface-chip txt-secondary',
}
</script>

<template>
  <span
    class="px-2 py-0.5 rounded-full text-[10px] font-medium uppercase"
    :class="tone[props.status] ?? tone.never_tested"
    :data-testid="`connection-status-${props.status}`"
  >
    {{ $t(`config.connections.status.${props.status}`, props.status) }}
  </span>
</template>
