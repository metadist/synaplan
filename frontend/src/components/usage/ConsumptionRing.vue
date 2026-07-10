<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useUsageTaximeterStore } from '@/stores/usageTaximeter'
import { formatCostDisplay, formatTokens } from '@/utils/usageFormat'
import UsageStatsPanel from '@/components/usage/UsageStatsPanel.vue'

/**
 * Mobile consumption ring (§1.4). The bar becomes a small ring/gauge shown at
 * the top-left of the chat area on < 768 px: today's charged spend (euro, not
 * percent) in the centre, per-model epoch tones as SVG arcs starting at 12
 * o'clock. Tap opens the session-statistics sheet; long-press shows the token
 * tooltip. Same store and gating as the desktop bar.
 */
const store = useUsageTaximeterStore()
const { t, locale } = useI18n()

const SIZE = 48
const STROKE = 5
const RADIUS = (SIZE - STROKE) / 2
const CIRC = 2 * Math.PI * RADIUS

const rootEl = ref<HTMLElement | null>(null)
const showTooltip = ref(false)
const showPanel = ref(false)

const HOLD_MS = 300
let holdTimer: ReturnType<typeof setTimeout> | null = null
let holdFired = false

const lessThanCent = computed(() => t('usageTaximeter.lessThanCent'))
const displayCost = computed(() =>
  formatCostDisplay(store.todayCost, locale.value, lessThanCent.value)
)
const tokenTooltip = computed(() =>
  t('usageTaximeter.todayTooltip', { tokens: formatTokens(store.todayTokens, locale.value) })
)
const ariaLabel = computed(() => t('usageTaximeter.todayAria', { cost: displayCost.value }))

// Consecutive arcs from the epoch segments, each described by its dash length
// and the negative offset of its start position along the (top-anchored) ring.
const arcs = computed(() => {
  let start = 0
  return store.epochSegments.map((seg, index) => {
    const ratio = Math.max(0, Math.min(1, seg.ratio))
    const length = ratio * CIRC
    const arc = {
      key: `${seg.tone}-${index}`,
      tone: seg.tone,
      dash: `${length} ${CIRC - length}`,
      offset: -start * CIRC,
    }
    start += ratio
    return arc
  })
})

function openPanel(): void {
  showPanel.value = true
  showTooltip.value = false
  document.addEventListener('keydown', onKeydown)
  document.addEventListener('pointerdown', onOutsidePointer, true)
}
function closePanel(): void {
  showPanel.value = false
  document.removeEventListener('keydown', onKeydown)
  document.removeEventListener('pointerdown', onOutsidePointer, true)
}
function togglePanel(): void {
  if (showPanel.value) {
    closePanel()
  } else {
    openPanel()
  }
}
function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') closePanel()
}
function onOutsidePointer(event: PointerEvent): void {
  if (rootEl.value && !rootEl.value.contains(event.target as Node)) {
    closePanel()
  }
}

function onTouchStart(): void {
  holdFired = false
  holdTimer = setTimeout(() => {
    holdFired = true
    showTooltip.value = true
  }, HOLD_MS)
}
function onTouchEnd(event: TouchEvent): void {
  if (holdTimer) {
    clearTimeout(holdTimer)
    holdTimer = null
  }
  event.preventDefault()
  if (holdFired) {
    showTooltip.value = false
  } else {
    togglePanel()
  }
}

onBeforeUnmount(() => {
  if (holdTimer) clearTimeout(holdTimer)
  document.removeEventListener('keydown', onKeydown)
  document.removeEventListener('pointerdown', onOutsidePointer, true)
})
</script>

<template>
  <div ref="rootEl" class="usage-ring" data-testid="usage-consumption-ring">
    <button
      type="button"
      class="usage-ring__trigger"
      :aria-label="ariaLabel"
      :aria-expanded="showPanel"
      @click="togglePanel"
      @touchstart.passive="onTouchStart"
      @touchend="onTouchEnd"
    >
      <svg :width="SIZE" :height="SIZE" :viewBox="`0 0 ${SIZE} ${SIZE}`" class="usage-ring__svg">
        <g :transform="`rotate(-90 ${SIZE / 2} ${SIZE / 2})`">
          <circle
            :cx="SIZE / 2"
            :cy="SIZE / 2"
            :r="RADIUS"
            fill="none"
            :stroke-width="STROKE"
            class="usage-ring__track"
          />
          <circle
            v-for="arc in arcs"
            :key="arc.key"
            :cx="SIZE / 2"
            :cy="SIZE / 2"
            :r="RADIUS"
            fill="none"
            stroke-linecap="round"
            :stroke-width="STROKE"
            :stroke-dasharray="arc.dash"
            :stroke-dashoffset="arc.offset"
            :class="arc.tone === 'b' ? 'usage-ring__arc--b' : 'usage-ring__arc--a'"
          />
        </g>
      </svg>
      <span class="usage-ring__value txt-primary">{{ displayCost }}</span>
    </button>

    <div v-if="showTooltip && !showPanel" class="usage-ring__tooltip surface-chip" role="tooltip">
      {{ tokenTooltip }}
    </div>

    <div v-if="showPanel" class="usage-ring__panel">
      <UsageStatsPanel @navigate="closePanel" />
    </div>
  </div>
</template>

<style scoped>
.usage-ring {
  position: absolute;
  top: 0.5rem;
  left: 0.5rem;
  z-index: 20;
  display: block;
}

/* Mobile only; the bar covers >= 768 px. */
@media (min-width: 768px) {
  .usage-ring {
    display: none;
  }
}

.usage-ring__trigger {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  background: transparent;
  border: none;
  cursor: pointer;
}

.usage-ring__svg {
  display: block;
}

.usage-ring__track {
  stroke: var(--usage-track);
}
.usage-ring__arc--a {
  stroke: var(--usage-epoch-a);
  transition: stroke-dasharray 0.3s ease;
}
.usage-ring__arc--b {
  stroke: var(--usage-epoch-b);
  transition: stroke-dasharray 0.3s ease;
}

.usage-ring__value {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.5rem;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  pointer-events: none;
}

.usage-ring__tooltip {
  position: absolute;
  left: 0;
  top: calc(100% + 0.375rem);
  padding: 0.25rem 0.5rem;
  border-radius: 0.375rem;
  font-size: 0.75rem;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
  pointer-events: none;
}

.usage-ring__panel {
  position: absolute;
  left: 0;
  top: calc(100% + 0.5rem);
}
</style>
