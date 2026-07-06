<template>
  <div class="flex h-dvh overflow-hidden" data-testid="comp-main-layout">
    <SidebarV2 />

    <!--
      Mobile push-drawer shell (§4.3): on small screens the content column
      slides to the right (~85%, leaving a 15% peek) to reveal the drawer
      underneath. On md+ this is just a plain flex column and the drawer /
      toggle are hidden — the desktop rail (SidebarV2) stays the navigation.
    -->
    <div class="v2-mobile-shell flex-1 flex min-w-0">
      <!-- Drawer: sits underneath the sliding content, mobile only -->
      <aside
        class="v2-mobile-drawer md:hidden"
        :aria-hidden="!sidebarStore.mobileDrawerOpen"
        :inert="!sidebarStore.mobileDrawerOpen"
        data-testid="nav-mobile-drawer"
      >
        <MobileNav />
      </aside>

      <!-- Sliding content layer -->
      <div
        class="v2-content-layer flex-1 flex flex-col min-w-0"
        :class="{ 'is-open': sidebarStore.mobileDrawerOpen }"
        data-testid="section-main-shell"
        @touchstart.passive="onTouchStart"
        @touchend="onTouchEnd"
      >
        <main
          class="flex-1 min-h-0 overflow-y-auto overscroll-contain"
          data-testid="section-primary-content"
        >
          <slot />
        </main>

        <!-- Tap-catcher over the peeking content closes the drawer (mobile only) -->
        <button
          v-if="sidebarStore.mobileDrawerOpen"
          class="v2-drawer-scrim md:hidden"
          :aria-label="$t('common.close')"
          data-testid="btn-mobile-drawer-scrim"
          @click="closeDrawer"
        />
      </div>
    </div>

    <!-- Mobile drawer toggle (top-left) — primary navigation entry on phones -->
    <button
      class="v2-drawer-toggle fixed left-3 z-40 md:hidden flex items-center justify-center w-10 h-10 rounded-full surface-card shadow-lg txt-primary active:scale-95 transition-transform"
      :aria-label="sidebarStore.mobileDrawerOpen ? $t('common.close') : $t('nav.menu')"
      :aria-expanded="sidebarStore.mobileDrawerOpen"
      data-testid="btn-mobile-drawer-toggle"
      @click="handleToggle"
    >
      <XMarkIcon v-if="sidebarStore.mobileDrawerOpen" class="w-6 h-6" aria-hidden="true" />
      <Bars3Icon v-else class="w-6 h-6" aria-hidden="true" />
    </button>

    <!-- Help system host -->
    <HelpHost />

    <!-- Global background-jobs tray (Release 4.0) — self-contained floating launcher -->
    <JobsTrayLauncher />
  </div>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { Bars3Icon, XMarkIcon } from '@heroicons/vue/24/outline'
import { useSidebarStore } from '../stores/sidebar'
import { triggerHapticImpact } from '../services/api/nativeHaptics'
import SidebarV2 from './SidebarV2.vue'
import MobileNav from './MobileNav.vue'
import HelpHost from './help/HelpHost.vue'
import JobsTrayLauncher from './jobs/JobsTrayLauncher.vue'

const route = useRoute()
const sidebarStore = useSidebarStore()

/** Edge zone (px) from the left where a closed-drawer open-swipe may start. */
const EDGE_ZONE_PX = 24
/** Minimum horizontal travel (px) that counts as a swipe. */
const SWIPE_THRESHOLD_PX = 60

let touchStartX = 0
let touchStartY = 0
let touchTracking = false

const isMobileViewport = () => window.matchMedia('(max-width: 767px)').matches

const fireHaptic = () => triggerHapticImpact('light')

const openDrawer = () => {
  fireHaptic()
  sidebarStore.openMobileDrawer()
}

/** Scrim / navigation close — intentionally without haptics. */
const closeDrawer = () => sidebarStore.closeMobileDrawer()

const handleToggle = () => {
  // The button tap itself always confirms with a single haptic pulse.
  fireHaptic()
  sidebarStore.toggleMobileDrawer()
}

const onTouchStart = (event: TouchEvent) => {
  if (!isMobileViewport()) {
    touchTracking = false
    return
  }
  const touch = event.touches[0]
  if (!touch) return
  touchStartX = touch.clientX
  touchStartY = touch.clientY
  // Closed: only an edge swipe from the left opens the drawer, so we never
  // hijack normal horizontal gestures inside the content. Open: a swipe from
  // anywhere may close it.
  touchTracking = sidebarStore.mobileDrawerOpen || touch.clientX <= EDGE_ZONE_PX
}

const onTouchEnd = (event: TouchEvent) => {
  if (!touchTracking) return
  touchTracking = false
  const touch = event.changedTouches[0]
  if (!touch) return

  const dx = touch.clientX - touchStartX
  const dy = touch.clientY - touchStartY
  // Ignore mostly-vertical gestures (scrolling).
  if (Math.abs(dx) <= Math.abs(dy)) return

  if (!sidebarStore.mobileDrawerOpen && dx > SWIPE_THRESHOLD_PX) {
    openDrawer()
  } else if (sidebarStore.mobileDrawerOpen && dx < -SWIPE_THRESHOLD_PX) {
    fireHaptic()
    sidebarStore.closeMobileDrawer()
  }
}

// Navigating away (via a drawer entry) always closes the drawer.
watch(
  () => route.fullPath,
  () => sidebarStore.closeMobileDrawer()
)

const handleEscape = (event: KeyboardEvent) => {
  if (event.key === 'Escape' && sidebarStore.mobileDrawerOpen) {
    sidebarStore.closeMobileDrawer()
  }
}

onMounted(() => document.addEventListener('keydown', handleEscape))
onBeforeUnmount(() => document.removeEventListener('keydown', handleEscape))
</script>

<style scoped>
.v2-mobile-shell {
  position: relative;
}

.v2-drawer-toggle {
  /* Sit inside the iOS safe area / notch, within the reserved top band. */
  top: calc(env(safe-area-inset-top, 0px) + 10px);
  touch-action: manipulation;
  -webkit-tap-highlight-color: transparent;
}

/* Mobile-only push-drawer mechanics. On md+ everything below is inert: the
   drawer is display:none and the content layer carries no transform. */
@media (max-width: 767px) {
  .v2-mobile-drawer {
    position: absolute;
    inset: 0 auto 0 0;
    width: 85vw;
    z-index: 0;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    /* Distinct from the content layer's --bg-app so the rounded corners of the
       shifted card read clearly against the drawer behind them. */
    background: var(--bg-sidebar);
  }

  .v2-content-layer {
    position: relative;
    z-index: 10;
    /* Opaque so the drawer underneath is fully hidden while closed. */
    background: var(--bg-app);
    /* Only transform is animated — it is GPU-composited, so the slide stays
       smooth even while the destination page renders on the main thread. The
       radius/shadow are snapped (not transitioned) on purpose: animating
       border-radius/box-shadow repaints every frame on the main thread and is
       exactly what made the close look laggy during navigation. */
    transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1);
    will-change: transform;
  }

  .v2-content-layer.is-open {
    transform: translateX(85%);
    /* Rounded corners make the shifted content read as a card floating over
       the drawer; overflow:hidden clips children (chat input, messages) to
       the radius so nothing pokes out of the rounded edge. The hairline ring
       (0 0 0 1px, rendered outside the clipped box) + drop shadow make the
       rounding clearly visible even when card and drawer share dark tones. */
    border-radius: 1.75rem;
    overflow: hidden;
    box-shadow:
      -14px 0 40px rgba(0, 0, 0, 0.4),
      0 0 0 1px var(--border-light);
  }

  .v2-drawer-scrim {
    position: absolute;
    inset: 0;
    z-index: 20;
    background: transparent;
    border: 0;
    cursor: pointer;
  }
}

@media (prefers-reduced-motion: reduce) {
  .v2-content-layer {
    transition: none;
  }
}
</style>
