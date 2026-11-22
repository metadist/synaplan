<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatDuration, partsOverlap, share, type RequestPart } from '@/utils/capacityTimeline'

/**
 * Where one request's time went, as one stacked bar. The bar is an estimate
 * and decoration: the legend (or the aria-label when the legend is hidden)
 * says every part in words, with what was measured for it.
 */
const props = defineProps<{
  parts: RequestPart[]
  total: number
  legend?: boolean
}>()

const { t } = useI18n()

const visible = computed(() => props.parts.filter((part) => part.ms > 0))
const overlap = computed(() => partsOverlap(props.parts, props.total))
const summary = computed(() =>
  props.parts
    .filter((part) => (part.measured ?? part.ms) > 0)
    .map((part) => `${t(`capacity.parts.${part.key}`)} ${formatDuration(part.measured ?? part.ms)}`)
    .join(' · ')
)
</script>

<template>
  <div class="space-y-2">
    <div
      class="flex h-2.5 w-full rounded-full overflow-hidden bg-[var(--status-neutral-muted)]"
      role="img"
      :aria-label="summary"
      data-testid="capacity-parts-bar"
    >
      <div
        v-for="part in visible"
        :key="part.key"
        class="h-full"
        :style="{ width: `${share(part.ms, total)}%`, backgroundColor: `var(--part-${part.key})` }"
      ></div>
    </div>
    <ul
      v-if="legend"
      class="flex flex-wrap gap-x-4 gap-y-1 text-xs"
      data-testid="capacity-parts-legend"
    >
      <li
        v-for="part in parts"
        :key="part.key"
        class="flex items-center gap-1.5 txt-secondary"
        :data-part="part.key"
      >
        <span
          class="inline-block w-2.5 h-2.5 rounded-sm"
          :style="{ backgroundColor: `var(--part-${part.key})` }"
          aria-hidden="true"
        ></span>
        <span>{{ t(`capacity.parts.${part.key}`) }}</span>
        <span class="txt-primary tabular-nums">{{ formatDuration(part.measured ?? part.ms) }}</span>
      </li>
    </ul>
    <p v-if="legend && overlap" class="text-xs txt-secondary" data-testid="capacity-parts-overlap">
      {{ t('capacity.parts.overlap') }}
    </p>
  </div>
</template>
