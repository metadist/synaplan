import { computed, nextTick, readonly, ref } from 'vue'
import { useSidebarStore } from '@/stores/sidebar'

/**
 * Desktop sidebar layout, three widths:
 *
 * - phone chrome (< 768 px or short): drawer, see usePhoneChrome.ts;
 * - 768–1023 px: icon rail only, the context panel opens as an overlay
 *   above the content, so the chat keeps its full width;
 * - from 1024 px: the panel docks beside the content and folds away with
 *   one click (remembered).
 *
 * The usage bar thresholds in ConsumptionBar.vue / ConsumptionRing.vue are
 * derived from these widths; keep them in step.
 */
export const PANEL_DOCK_MIN_WIDTH = 1024
export const PANEL_DOCK_MQ = `(min-width: ${PANEL_DOCK_MIN_WIDTH}px)`

const dockable = ref(true)
let listening = false

/** App-lifetime singleton: one media listener for every consumer. */
function listenToViewport(): void {
  if (listening || typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
    return
  }
  listening = true
  const query = window.matchMedia(PANEL_DOCK_MQ)
  dockable.value = query.matches
  query.addEventListener('change', (event) => {
    dockable.value = event.matches
  })
}

const TOGGLE_TEST_IDS = {
  expand: 'btn-sidebar-v2-expand',
  collapse: 'btn-sidebar-v2-collapse',
} as const

/**
 * After an explicit open or close, put keyboard focus on the control that
 * reverses it, so the panel never strands focus on a removed element.
 */
export function focusSidebarToggle(which: keyof typeof TOGGLE_TEST_IDS): void {
  void nextTick(() => {
    document.querySelector<HTMLElement>(`[data-testid="${TOGGLE_TEST_IDS[which]}"]`)?.focus()
  })
}

export function useSidebarLayout() {
  listenToViewport()
  const sidebarStore = useSidebarStore()

  const panelDocked = computed(() => dockable.value && !sidebarStore.isCollapsed)
  const panelOverlay = computed(() => !dockable.value && sidebarStore.panelOverlayOpen)
  const panelVisible = computed(() => panelDocked.value || panelOverlay.value)

  const openPanel = () => {
    if (dockable.value) {
      sidebarStore.isCollapsed = false
    } else {
      sidebarStore.panelOverlayOpen = true
    }
  }

  const closePanel = () => {
    if (dockable.value) {
      sidebarStore.isCollapsed = true
    } else {
      sidebarStore.panelOverlayOpen = false
    }
  }

  const togglePanel = () => (panelVisible.value ? closePanel() : openPanel())

  return {
    dockable: readonly(dockable),
    panelDocked,
    panelOverlay,
    panelVisible,
    openPanel,
    closePanel,
    togglePanel,
  }
}
