import { defineStore } from 'pinia'
import { ref, watch } from 'vue'

const PANEL_COLLAPSED_KEY = 'sidebar-panel-collapsed'

function readFlag(key: string): boolean {
  try {
    return localStorage.getItem(key) === 'true'
  } catch {
    return false
  }
}

export const useSidebarStore = defineStore('sidebar', () => {
  const isOpen = ref(false)
  /**
   * Desktop context panel folded away, leaving only the icon rail. Own key:
   * the retired sidebar wrote `sidebar-collapsed`, and a stale value from it
   * must not fold the panel on someone's first visit.
   */
  const isCollapsed = ref(readFlag(PANEL_COLLAPSED_KEY))
  /** Below the dock width the panel opens above the content. Never remembered. */
  const panelOverlayOpen = ref(false)
  const showChats = ref(localStorage.getItem('sidebar-show-chats') !== 'false')

  /**
   * Mobile push-drawer (primary navigation on small screens). Opening it slides
   * the content column to the right and reveals the drawer (nav buttons + chat
   * history) underneath. Desktop is unaffected.
   */
  const mobileDrawerOpen = ref(false)

  const openMobileDrawer = () => {
    mobileDrawerOpen.value = true
  }

  const closeMobileDrawer = () => {
    mobileDrawerOpen.value = false
  }

  const toggleMobileDrawer = () => {
    mobileDrawerOpen.value = !mobileDrawerOpen.value
  }

  // Disclosure state for chat groups
  const chatDisclosure = ref({
    my: localStorage.getItem('sidebar-disclosure-my') !== 'false',
    widget: localStorage.getItem('sidebar-disclosure-widget') === 'true',
  })

  watch(isCollapsed, (value) => {
    try {
      localStorage.setItem(PANEL_COLLAPSED_KEY, String(value))
    } catch {
      // Private mode can block storage. The panel still folds for this visit.
    }
  })

  watch(showChats, (value) => {
    localStorage.setItem('sidebar-show-chats', String(value))
  })

  watch(
    () => chatDisclosure.value.my,
    (value) => {
      localStorage.setItem('sidebar-disclosure-my', String(value))
    }
  )

  watch(
    () => chatDisclosure.value.widget,
    (value) => {
      localStorage.setItem('sidebar-disclosure-widget', String(value))
    }
  )

  const toggle = () => {
    isOpen.value = !isOpen.value
  }

  const close = () => {
    isOpen.value = false
  }

  const open = () => {
    isOpen.value = true
  }

  const toggleCollapsed = () => {
    isCollapsed.value = !isCollapsed.value
  }

  const toggleShowChats = () => {
    showChats.value = !showChats.value
  }

  const toggleChatDisclosure = (section: 'my' | 'widget') => {
    chatDisclosure.value[section] = !chatDisclosure.value[section]
  }

  return {
    isOpen,
    isCollapsed,
    panelOverlayOpen,
    showChats,
    chatDisclosure,
    mobileDrawerOpen,
    toggle,
    close,
    open,
    toggleCollapsed,
    toggleShowChats,
    toggleChatDisclosure,
    openMobileDrawer,
    closeMobileDrawer,
    toggleMobileDrawer,
  }
})
