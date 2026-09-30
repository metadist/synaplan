import { defineStore } from 'pinia'
import { ref } from 'vue'

/**
 * Open state of the global Smart Search palette. Any surface (sidebar
 * button, mobile drawer, Ctrl/Cmd+K, a "search model" link) opens the same
 * palette through this store.
 */
export const useSmartSearchStore = defineStore('smartSearch', () => {
  const isOpen = ref(false)
  const initialQuery = ref('')

  const open = (query = '') => {
    initialQuery.value = query
    isOpen.value = true
  }

  const close = () => {
    isOpen.value = false
  }

  const toggle = () => {
    if (isOpen.value) {
      close()
    } else {
      open()
    }
  }

  return { isOpen, initialQuery, open, close, toggle }
})
