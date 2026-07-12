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

    <!-- Guest login shortcut (top-right) — mirrors the drawer toggle on the
         left. Only for signed-out users; hidden while the drawer is open. -->
    <button
      v-if="showLoginButton"
      class="v2-login-cta fixed right-3 z-40 md:hidden inline-flex items-center gap-1.5 h-10 px-4 rounded-full btn-primary shadow-lg text-sm font-semibold active:scale-95 transition-transform"
      data-testid="btn-mobile-login-cta"
      @click="goToLogin"
    >
      <ArrowRightOnRectangleIcon class="w-5 h-5" aria-hidden="true" />
      <span>{{ $t('auth.signIn') }}</span>
    </button>

    <!-- Incognito toggle (top-right, mobile) — mirrors the drawer toggle on
         the left. Signed-in users on the chat route only (guests have the
         login CTA in that spot and no incognito). Desktop gets its own
         floating instance inside ChatView. -->
    <div v-if="showIncognitoToggle" class="v2-incognito-toggle fixed right-3 z-40 md:hidden">
      <IncognitoToggle />
    </div>

    <!-- Help system host -->
    <HelpHost />

    <!-- Global background-jobs tray (Release 4.0) — self-contained floating launcher -->
    <JobsTrayLauncher />
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Bars3Icon, XMarkIcon, ArrowRightOnRectangleIcon } from '@heroicons/vue/24/outline'
import { useSidebarStore } from '../stores/sidebar'
import { useAuthStore } from '../stores/auth'
import { triggerHapticImpact } from '../services/api/nativeHaptics'
import SidebarV2 from './SidebarV2.vue'
import MobileNav from './MobileNav.vue'
import HelpHost from './help/HelpHost.vue'
import JobsTrayLauncher from './jobs/JobsTrayLauncher.vue'
import IncognitoToggle from './IncognitoToggle.vue'

const route = useRoute()
const router = useRouter()
const sidebarStore = useSidebarStore()
const authStore = useAuthStore()

// Signed-out users get a prominent login shortcut in the top bar. Hidden while
// the drawer is open so it never overlaps the sliding content / close button.
const showLoginButton = computed(() => !authStore.isAuthenticated && !sidebarStore.mobileDrawerOpen)

// Incognito toggle (mobile, top-right): signed-in users on the chat route only.
const showIncognitoToggle = computed(
  () => authStore.isAuthenticated && route.name === 'chat' && !sidebarStore.mobileDrawerOpen
)

const goToLogin = () => {
  fireHaptic()
  router.push('/login')
}

/** Minimum horizontal travel (px) that counts as a swipe. */
const SWIPE_THRESHOLD_PX = 60

let touchStartX = 0
let touchStartY = 0
let touchTracking = false

const isMobileViewport = () => window.matchMedia('(max-width: 767px)').matches

/**
 * Walk up from the touch target: if any ancestor can actually scroll
 * horizontally (wide table, code block, carousel, …), the horizontal gesture
 * belongs to THAT element, not the drawer. Without this, dragging such content
 * left↔right is misread as a drawer swipe and pops the menu open (issue: menu
 * opens while scrolling a table sideways).
 */
const startedInHorizontalScroller = (target: EventTarget | null): boolean => {
  let node: Element | null = target instanceof Element ? target : null
  while (node && node !== document.body) {
    if (node.scrollWidth > node.clientWidth) {
      const overflowX = window.getComputedStyle(node).overflowX
      if (overflowX === 'auto' || overflowX === 'scroll') return true
    }
    node = node.parentElement
  }
  return false
}

const fireHaptic = () => triggerHapticImpact('light')

const openDrawer = () => {
  fireHaptic()
  // Swiping the drawer open while a text field is focused (e.g. the chat
  // input) must dismiss the on-screen keyboard first — a drag gesture moves
  // well past the tap-dismiss tolerance below, so it never triggered a blur
  // and the keyboard stayed open over the newly revealed drawer.
  const active = document.activeElement as HTMLElement | null
  if (isEditableEl(active)) {
    active!.blur()
  }
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
  if (!isMobileViewport() || startedInHorizontalScroller(event.target)) {
    touchTracking = false
    return
  }
  const touch = event.touches[0]
  if (!touch) return
  touchStartX = touch.clientX
  touchStartY = touch.clientY
  // Track every horizontal gesture on the content layer: a left-to-right swipe
  // starting anywhere opens the drawer, a right-to-left swipe closes it. The
  // vertical-dominance check in onTouchEnd keeps normal scrolling untouched.
  touchTracking = true
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

// Close the drawer on EVERY navigation, from a single place. Doing it in a
// global guard (rather than a route watcher that fires after the destination
// has mounted) means the close always starts at the same point in the
// navigation lifecycle — before the new page renders. The slide is a
// GPU-composited transform, so it keeps animating smoothly while the
// destination renders, giving a consistent close regardless of what triggered
// the navigation (drawer link, browser back, in-page link, jobs tray, ...).
const removeNavGuard = router.beforeEach((_to, _from, next) => {
  if (sidebarStore.mobileDrawerOpen) {
    sidebarStore.closeMobileDrawer()
  }
  next()
})

const handleEscape = (event: KeyboardEvent) => {
  if (event.key === 'Escape' && sidebarStore.mobileDrawerOpen) {
    sidebarStore.closeMobileDrawer()
  }
}

const EDITABLE_SELECTOR = 'input, textarea, select, [contenteditable=""], [contenteditable="true"]'

/** Interactive targets that must keep their own tap behavior in the top band. */
const INTERACTIVE_SELECTOR = `button, a, [role="button"], [role="tab"], ${EDITABLE_SELECTOR}`

/**
 * Height (px) of the tap-to-top band at the very top of the screen: the iOS
 * safe area (notch / Dynamic Island / status bar) plus a small buffer. Measured
 * from a probe because `env()` only resolves in CSS, and re-measured on resize
 * (orientation change flips the inset).
 */
let topTapBandPx = 40

const measureTopTapBand = () => {
  const probe = document.createElement('div')
  probe.style.cssText =
    'position:fixed;top:0;left:0;width:0;height:env(safe-area-inset-top,0px);visibility:hidden;pointer-events:none;'
  document.body.appendChild(probe)
  topTapBandPx = probe.getBoundingClientRect().height + 40
  probe.remove()
}

/**
 * Tapping the very top of the screen scrolls the current page back to the top —
 * the familiar iOS status-bar-tap behavior, which the native WKWebView does not
 * provide for our inner overflow containers (the document itself never scrolls).
 */
const scrollToTopOnBandTap = (event: PointerEvent) => {
  if (!isMobileViewport()) return
  if (event.clientY > topTapBandPx) return
  const target = event.target as HTMLElement | null
  if (target && target.closest(INTERACTIVE_SELECTOR)) return

  const main = document.querySelector<HTMLElement>('[data-testid="section-primary-content"]')
  if (!main) return
  const containers: HTMLElement[] = [
    main,
    ...main.querySelectorAll<HTMLElement>('.overflow-y-auto'),
  ]
  containers
    .filter((el) => el.scrollTop > 0)
    .forEach((el) => el.scrollTo({ top: 0, behavior: 'smooth' }))
}

/**
 * Dismiss the on-screen keyboard when the user *taps* outside the focused
 * field. iOS keeps a text control focused (keyboard up) until it is explicitly
 * blurred, so a tap on empty page area would otherwise never close it.
 *
 * Crucially we must distinguish a TAP from a SCROLL: blurring on `pointerdown`
 * would kill the keyboard the moment the user starts scrolling the chat. So we
 * record the start position and only blur on `pointerup` when the pointer
 * barely moved (a genuine tap). A scroll/drag moves past the tolerance and
 * leaves the keyboard open — the keyboard then only closes on a real tap or the
 * native swipe-down-through-keyboard gesture.
 */
const KEYBOARD_TAP_MOVE_TOLERANCE_PX = 10

let keyboardTapStartX = 0
let keyboardTapStartY = 0
let keyboardTapCandidate = false

const isEditableEl = (el: HTMLElement | null): boolean =>
  !!el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.isContentEditable)

const onKeyboardDismissPointerDown = (event: PointerEvent) => {
  keyboardTapCandidate = false
  if (!isEditableEl(document.activeElement as HTMLElement | null)) return
  const target = event.target as HTMLElement | null
  // A press that starts inside an editable control is never a dismiss.
  if (target && target.closest(EDITABLE_SELECTOR)) return
  keyboardTapStartX = event.clientX
  keyboardTapStartY = event.clientY
  keyboardTapCandidate = true
}

const onKeyboardDismissPointerUp = (event: PointerEvent) => {
  if (!keyboardTapCandidate) return
  keyboardTapCandidate = false
  // Moved too far → this was a scroll/drag, not a tap: keep the keyboard open.
  if (
    Math.abs(event.clientX - keyboardTapStartX) > KEYBOARD_TAP_MOVE_TOLERANCE_PX ||
    Math.abs(event.clientY - keyboardTapStartY) > KEYBOARD_TAP_MOVE_TOLERANCE_PX
  ) {
    return
  }
  const active = document.activeElement as HTMLElement | null
  if (!isEditableEl(active)) return
  const target = event.target as HTMLElement | null
  // Tapped into another editable control — let it keep focus.
  if (target && target.closest(EDITABLE_SELECTOR)) return
  active!.blur()
}

// A touch that turns into a scroll fires pointercancel (not pointerup) once the
// browser claims the gesture — reset so no stray blur happens afterwards.
const onKeyboardDismissPointerCancel = () => {
  keyboardTapCandidate = false
}

onMounted(() => {
  measureTopTapBand()
  document.addEventListener('keydown', handleEscape)
  document.addEventListener('pointerdown', onKeyboardDismissPointerDown)
  document.addEventListener('pointerup', onKeyboardDismissPointerUp)
  document.addEventListener('pointercancel', onKeyboardDismissPointerCancel)
  document.addEventListener('pointerdown', scrollToTopOnBandTap)
  window.addEventListener('resize', measureTopTapBand)
})
onBeforeUnmount(() => {
  document.removeEventListener('keydown', handleEscape)
  document.removeEventListener('pointerdown', onKeyboardDismissPointerDown)
  document.removeEventListener('pointerup', onKeyboardDismissPointerUp)
  document.removeEventListener('pointercancel', onKeyboardDismissPointerCancel)
  document.removeEventListener('pointerdown', scrollToTopOnBandTap)
  window.removeEventListener('resize', measureTopTapBand)
  removeNavGuard()
})
</script>

<style scoped>
.v2-mobile-shell {
  position: relative;
}

.v2-drawer-toggle,
.v2-login-cta,
.v2-incognito-toggle {
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
