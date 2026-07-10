<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useUsageTaximeterStore } from '@/stores/usageTaximeter'
import { formatCostDisplay, formatTokens } from '@/utils/usageFormat'
import UsageStatsPanel from '@/components/usage/UsageStatsPanel.vue'

/**
 * Desktop consumption bar (§1.1). A slim vertical rail on the right of the chat
 * area: today's charged spend at the head, the money-scale fill (with per-model
 * epoch tones) in the track, and the "Session" label at the foot. Hover shows a
 * token tooltip; click opens the shared session-statistics popover. Tooltip and
 * popover are mutually exclusive and never overlap the composer/messages.
 *
 * Only rendered by the parent when the taximeter is active (auth + admin
 * switch) and on >= 768 px (the ring covers mobile).
 */
const store = useUsageTaximeterStore()
const { t, locale } = useI18n()

const rootEl = ref<HTMLElement | null>(null)
const showTooltip = ref(false)
const showPanel = ref(false)
const suppressTransition = ref(false)

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

// Segment heights as % of the track. The unfilled remainder stays at the top
// because the track packs segments from the bottom (column-reverse).
const segments = computed(() =>
  store.epochSegments.map((s, index) => ({
    key: `${s.tone}-${index}`,
    tone: s.tone,
    heightPct: Math.max(0, Math.min(100, s.ratio * 100)),
  }))
)

// A scale jump must not animate (the drop to half-fill is intentionally
// abrupt); suppress the transition for one paint, then re-enable it.
watch(
  () => store.justRescaled,
  (jumped) => {
    if (!jumped) return
    suppressTransition.value = true
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        suppressTransition.value = false
        store.clearRescaleFlag()
      })
    })
  }
)

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

// Mouse: hover shows the tooltip (unless the panel is open).
function onMouseEnter(): void {
  if (!showPanel.value) showTooltip.value = true
}
function onMouseLeave(): void {
  showTooltip.value = false
}

// Touch: a long press shows the tooltip; a short tap opens the panel.
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
  // Suppress the synthetic click so we don't also toggle via @click.
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
  <div ref="rootEl" class="usage-bar" data-testid="usage-consumption-bar">
    <button
      type="button"
      class="usage-bar__trigger"
      :class="{ 'usage-bar__trigger--no-anim': suppressTransition }"
      :aria-label="ariaLabel"
      :aria-expanded="showPanel"
      @mouseenter="onMouseEnter"
      @mouseleave="onMouseLeave"
      @click="togglePanel"
      @touchstart.passive="onTouchStart"
      @touchend="onTouchEnd"
    >
      <span class="usage-bar__head txt-primary">{{ displayCost }}</span>
      <span class="usage-bar__track">
        <span
          v-for="seg in segments"
          :key="seg.key"
          class="usage-bar__segment"
          :class="seg.tone === 'b' ? 'usage-bar__segment--b' : 'usage-bar__segment--a'"
          :style="{ height: seg.heightPct + '%' }"
        />
      </span>
      <span class="usage-bar__foot txt-secondary">{{ t('usageTaximeter.sessionLabel') }}</span>
    </button>

    <div v-if="showTooltip && !showPanel" class="usage-bar__tooltip surface-chip" role="tooltip">
      {{ tokenTooltip }}
    </div>

    <div v-if="showPanel" class="usage-bar__panel">
      <UsageStatsPanel @navigate="closePanel" />
    </div>
  </div>
</template>

<style scoped>
.usage-bar {
  position: absolute;
  top: 50%;
  right: 0.5rem;
  transform: translateY(-50%);
  z-index: 20;
  display: none;
}

/* Desktop only; the ring covers < 768 px. */
@media (min-width: 768px) {
  .usage-bar {
    display: block;
  }
}

.usage-bar__trigger {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.375rem;
  padding: 0.375rem 0.25rem;
  background: transparent;
  border: none;
  cursor: pointer;
}

.usage-bar__head {
  font-size: 0.75rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.usage-bar__track {
  position: relative;
  display: flex;
  flex-direction: column-reverse;
  width: 0.5rem;
  height: 9rem;
  border-radius: 9999px;
  background: var(--usage-track);
  overflow: hidden;
}

.usage-bar__segment {
  width: 100%;
  transition: height 0.3s ease;
}
.usage-bar__trigger--no-anim .usage-bar__segment {
  transition: none;
}
.usage-bar__segment--a {
  background: var(--usage-epoch-a);
}
.usage-bar__segment--b {
  background: var(--usage-epoch-b);
}

.usage-bar__foot {
  font-size: 0.625rem;
  writing-mode: vertical-rl;
  text-orientation: mixed;
  transform: rotate(180deg);
  letter-spacing: 0.02em;
}

.usage-bar__tooltip {
  position: absolute;
  right: calc(100% + 0.5rem);
  top: 50%;
  transform: translateY(-50%);
  padding: 0.25rem 0.5rem;
  border-radius: 0.375rem;
  font-size: 0.75rem;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
  pointer-events: none;
}

.usage-bar__panel {
  position: absolute;
  right: calc(100% + 0.5rem);
  top: 50%;
  transform: translateY(-50%);
}
</style>
