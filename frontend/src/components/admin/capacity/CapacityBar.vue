<script setup lang="ts">
import { computed } from 'vue'
import { ratioPercent } from '@/utils/capacityFacts'

/**
 * One measured line of a server card: a label, a filled bar and the reading
 * in words. The bar is decoration for the number next to it, so the number
 * is always rendered as text.
 */
const props = defineProps<{
  label: string
  used: number
  total: number
  reading: string
  note?: string
  testId: string
}>()

const percent = computed(() => ratioPercent(props.used, props.total))

const tone = computed(() => {
  if (props.total <= 0) return 'var(--status-neutral)'
  if (percent.value >= 90) return 'var(--status-error)'
  if (percent.value >= 75) return 'var(--status-warning)'
  return 'var(--brand)'
})
</script>

<template>
  <div
    class="grid grid-cols-[minmax(6rem,8rem)_1fr] sm:grid-cols-[8rem_1fr_auto] items-center gap-x-3 gap-y-1"
    :data-testid="testId"
  >
    <span class="text-sm txt-secondary">{{ label }}</span>
    <div
      class="h-2.5 w-full rounded-full overflow-hidden bg-[var(--status-neutral-muted)]"
      role="progressbar"
      :aria-label="label"
      :aria-valuenow="percent"
      aria-valuemin="0"
      aria-valuemax="100"
    >
      <div
        class="h-full rounded-full transition-[width]"
        :style="{ width: `${percent}%`, backgroundColor: tone }"
      ></div>
    </div>
    <span
      class="col-span-2 sm:col-span-1 min-w-0 text-sm txt-primary tabular-nums"
      :data-testid="`${testId}-reading`"
    >
      {{ reading }}<span v-if="note" class="txt-secondary"> · {{ note }}</span>
    </span>
  </div>
</template>
