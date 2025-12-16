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
    expect(store.isMobileOpen).toBe(false)
  })

  it('should toggle collapsed state', () => {
    const store = useSidebarStore()
    
    store.toggleCollapsed()
    expect(store.isCollapsed).toBe(true)
    
    store.toggleCollapsed()
    expect(store.isCollapsed).toBe(false)
  })

  it('should open mobile sidebar', () => {
    const store = useSidebarStore()
    
    store.openMobile()
    expect(store.isMobileOpen).toBe(true)
  })

  it('should close mobile sidebar', () => {
    const store = useSidebarStore()
    store.isMobileOpen = true
    
    store.closeMobile()
    expect(store.isMobileOpen).toBe(false)
  })

  it('should toggle mobile sidebar', () => {
    const store = useSidebarStore()
    
    store.toggleMobile()
    expect(store.isMobileOpen).toBe(true)
    
    store.toggleMobile()
    expect(store.isMobileOpen).toBe(false)
  })
})

