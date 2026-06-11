import { defineStore } from 'pinia'
import { ref, watch } from 'vue'

export const useSidebarStore = defineStore('sidebar', () => {
  const isOpen = ref(false)
  const isMobileOpen = ref(false)
  const isCollapsed = ref(localStorage.getItem('sidebar-collapsed') === 'true')
  const showChats = ref(localStorage.getItem('sidebar-show-chats') !== 'false')

  /**
   * History sheet (recent chats). Shared state so both the desktop rail and
   * the mobile bottom nav can open the same sheet (rendered by SidebarV2).
   */
  const chatSheetOpen = ref(false)

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

  const openMobile = () => {
    isMobileOpen.value = true
  }

  const closeMobile = () => {
    isMobileOpen.value = false
  }

  const toggleMobile = () => {
    isMobileOpen.value = !isMobileOpen.value
  }

  return {
    isOpen,
    isMobileOpen,
    isCollapsed,
    showChats,
    chatDisclosure,
    chatSheetOpen,
    toggle,
    close,
    open,
    toggleCollapsed,
    toggleShowChats,
    toggleChatDisclosure,
    openChatSheet,
    closeChatSheet,
    toggleChatSheet,
    openMobile,
    closeMobile,
    toggleMobile,
  }
})
