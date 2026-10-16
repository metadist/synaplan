import { defineStore } from 'pinia'
import { ref } from 'vue'

/**
 * Open state of the global Smart Search palette. Any surface (sidebar
 * button, mobile drawer, Ctrl/Cmd+K, a "search model" link) opens the same
 * palette through this store.
 *
 * `pendingAsk` carries an "Ask in chat" question to ChatView. It lives in
 * memory only, so a link or bookmark can never send a message on the
 * person's behalf.
 */
export const useSmartSearchStore = defineStore('smartSearch', () => {
  const isOpen = ref(false)
  const initialQuery = ref('')
  const pendingAsk = ref<string | null>(null)

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

  const askInChat = (text: string) => {
    const trimmed = text.trim()
    pendingAsk.value = trimmed === '' ? null : trimmed
  }

  /** Returns the pending question once and clears it. */
  const takePendingAsk = (): string | null => {
    const text = pendingAsk.value
    pendingAsk.value = null
    return text
  }

  /** Drops everything tied to the current person (logout, impersonation). */
  const reset = () => {
    isOpen.value = false
    initialQuery.value = ''
    pendingAsk.value = null
  }

  return {
    isOpen,
    initialQuery,
    pendingAsk,
    open,
    close,
    toggle,
    askInChat,
    takePendingAsk,
    reset,
  }
})
