import { describe, it, expect, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useSidebarStore } from '@/stores/sidebar'

describe('Sidebar Store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('should initialize with default values', () => {
    const store = useSidebarStore()

    expect(store.isCollapsed).toBe(false)
    expect(store.chatSheetOpen).toBe(false)
  })

  it('should toggle collapsed state', () => {
    const store = useSidebarStore()

    store.toggleCollapsed()
    expect(store.isCollapsed).toBe(true)

    store.toggleCollapsed()
    expect(store.isCollapsed).toBe(false)
  })

  it('should open the history sheet', () => {
    const store = useSidebarStore()

    store.openChatSheet()
    expect(store.chatSheetOpen).toBe(true)
  })

  it('should close the history sheet', () => {
    const store = useSidebarStore()
    store.chatSheetOpen = true

    store.closeChatSheet()
    expect(store.chatSheetOpen).toBe(false)
  })

  it('should toggle the history sheet', () => {
    const store = useSidebarStore()

    store.toggleChatSheet()
    expect(store.chatSheetOpen).toBe(true)

    store.toggleChatSheet()
    expect(store.chatSheetOpen).toBe(false)
  })
})
