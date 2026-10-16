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
    expect(store.mobileDrawerOpen).toBe(false)
  })

  it('should toggle collapsed state', () => {
    const store = useSidebarStore()

    store.toggleCollapsed()
    expect(store.isCollapsed).toBe(true)

    store.toggleCollapsed()
    expect(store.isCollapsed).toBe(false)
  })

  it('should toggle the mobile drawer', () => {
    const store = useSidebarStore()

    store.toggleMobileDrawer()
    expect(store.mobileDrawerOpen).toBe(true)

    store.closeMobileDrawer()
    expect(store.mobileDrawerOpen).toBe(false)
  })
})
