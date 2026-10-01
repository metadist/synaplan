import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useSmartSearchStore } from '@/stores/smartSearch'

describe('smartSearch store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('hands an "Ask in chat" question over exactly once', () => {
    const store = useSmartSearchStore()
    store.askInChat('  how do I share a folder?  ')

    expect(store.takePendingAsk()).toBe('how do I share a folder?')
    expect(store.takePendingAsk()).toBeNull()
    expect(store.pendingAsk).toBeNull()
  })

  it('keeps nothing for a blank question', () => {
    const store = useSmartSearchStore()
    store.askInChat('   ')
    expect(store.pendingAsk).toBeNull()
  })
})
