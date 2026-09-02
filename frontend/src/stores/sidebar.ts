import { defineStore } from 'pinia'
import { ref, watch } from 'vue'

export const useSidebarStore = defineStore('sidebar', () => {
  const isOpen = ref(false)
  const isCollapsed = ref(localStorage.getItem('sidebar-collapsed') === 'true')
  const showChats = ref(localStorage.getItem('sidebar-show-chats') !== 'false')

  /**
   * History sheet (recent chats). Shared state so both the desktop rail and
   * the mobile bottom nav can open the same sheet (rendered by SidebarV2).
   */
  const chatSheetOpen = ref(false)

  /**
   * Mobile push-drawer (primary navigation on small screens). Opening it slides
   * the content column to the right and reveals the drawer (nav buttons + chat
   * history) underneath. Desktop is unaffected.
   */
  const mobileDrawerOpen = ref(false)

  /**
   * Desktop rail open (chat list visible, ~248px) or the 80px icon rail.
   * Open by default — the user must see and pick a chat without an extra click.
   * A new storage key so earlier experiment flags cannot leave the list hidden.
   */
  const railExpanded = ref(localStorage.getItem('synaplan_rail_open') !== 'false')

  const toggleRail = () => {
    railExpanded.value = !railExpanded.value
  }

  watch(railExpanded, (value) => {
    localStorage.setItem('synaplan_rail_open', String(value))
  })

  const openMobileDrawer = () => {
    mobileDrawerOpen.value = true
  }

  const closeMobileDrawer = () => {
    mobileDrawerOpen.value = false
  }

  const toggleMobileDrawer = () => {
    mobileDrawerOpen.value = !mobileDrawerOpen.value
  }

  const openChatSheet = () => {
    chatSheetOpen.value = true
  }

  const closeChatSheet = () => {
    chatSheetOpen.value = false
  }

  const toggleChatSheet = () => {
    chatSheetOpen.value = !chatSheetOpen.value
  }

  // Disclosure state for chat groups
  const chatDisclosure = ref({
    my: localStorage.getItem('sidebar-disclosure-my') !== 'false',
    widget: localStorage.getItem('sidebar-disclosure-widget') === 'true',
  })

  watch(isCollapsed, (value) => {
    localStorage.setItem('sidebar-collapsed', String(value))
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
    showChats,
    chatDisclosure,
    chatSheetOpen,
    mobileDrawerOpen,
    railExpanded,
    toggleRail,
    toggle,
    close,
    open,
    toggleCollapsed,
    toggleShowChats,
    toggleChatDisclosure,
    openChatSheet,
    closeChatSheet,
    toggleChatSheet,
    openMobileDrawer,
    closeMobileDrawer,
    toggleMobileDrawer,
  }
})
