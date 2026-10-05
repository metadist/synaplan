<script setup lang="ts">
/**
 * Global ambient background: the brand bird watermark plus a faint wandering
 * spotlight, on every page of the app.
 *
 * The bird keeps the login page geometry (280px, 3.5% / 6% opacity) and drifts
 * per page: up to +20 points right/down from its base corner and a random tilt
 * of up to 15 degrees counterclockwise from its base rotation. The spotlight is
 * a ~4% radial glow that wanders on a slow compositor-only loop — one
 * transform-animated layer, no per-frame JS, frozen under
 * prefers-reduced-motion.
 *
 * The layer sits above flat page content (page roots paint opaque backgrounds,
 * so a true behind-content layer would be invisible) at an opacity where it
 * reads as paper grain, never as haze: pointer-events-none, aria-hidden, below
 * floating UI (dropdowns, toggles, modals). Hidden while E2E drives
 * (navigator.webdriver) so the random placement cannot flake visual snapshots.
 */
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useBrandLogo } from '@/composables/useBrandLogo'
import { useTheme } from '@/composables/useTheme'
import { randomizeAmbientPlacement, type AmbientPlacement } from '@/utils/ambientPlacement'

const route = useRoute()
const { isDark } = useTheme()
const { iconSrc } = useBrandLogo(isDark)

const placement = ref<AmbientPlacement>(randomizeAmbientPlacement())

watch(
  () => route.path,
  () => {
    placement.value = randomizeAmbientPlacement()
  }
)

const isE2E = typeof navigator !== 'undefined' && navigator.webdriver === true

const birdStyle = computed(() => ({
  top: `${placement.value.topPct.toFixed(2)}%`,
  right: `${placement.value.rightPct.toFixed(2)}%`,
  transform: `rotate(${placement.value.rotateDeg.toFixed(2)}deg)`,
}))
</script>

<template>
  <div
    v-if="!isE2E"
    data-testid="ambient-background"
    aria-hidden="true"
    class="pointer-events-none fixed inset-0 z-[1] overflow-hidden"
  >
    <div class="ambient-spotlight" data-testid="ambient-spotlight" />
    <img
      :src="iconSrc"
      alt=""
      draggable="false"
      :style="birdStyle"
      class="absolute w-[clamp(180px,30vw,300px)] opacity-[0.035] select-none pointer-events-none dark:opacity-[0.06]"
      data-testid="ambient-bird"
    />
  </div>
</template>

<style scoped>
.ambient-spotlight {
  position: absolute;
  inset: -25vmax;
  background: radial-gradient(circle at 50% 42%, rgba(0, 63, 199, 0.04), transparent 60%);
  animation: ambient-spotlight-wander 80s linear infinite;
  will-change: transform;
}
.dark .ambient-spotlight {
  background: radial-gradient(circle at 50% 42%, rgba(147, 197, 253, 0.05), transparent 60%);
}
@keyframes ambient-spotlight-wander {
  0% {
    transform: translate(-8vmax, -4vmax);
  }
  25% {
    transform: translate(6vmax, 5vmax);
  }
  50% {
    transform: translate(9vmax, -6vmax);
  }
  75% {
    transform: translate(-5vmax, 7vmax);
  }
  100% {
    transform: translate(-8vmax, -4vmax);
  }
}
@media (prefers-reduced-motion: reduce) {
  .ambient-spotlight {
    animation: none;
  }
}
</style>
