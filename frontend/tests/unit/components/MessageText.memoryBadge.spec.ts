import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import MessageText from '@/components/MessageText.vue'
import { focusUsedMemoryKey } from '@/components/chat/focusUsedMemory'
import { useMemoriesStore } from '@/stores/userMemories'
import type { UserMemory } from '@/services/api/userMemoriesApi'

const memory: UserMemory = {
  id: 42,
  category: 'preferences',
  key: 'favorite_color',
  value: 'teal',
  source: 'user_created',
  messageId: null,
  created: 1,
  updated: 1,
}

function messageTextEl(wrapper: ReturnType<typeof mount>): HTMLElement {
  return wrapper.get('[data-testid="message-text"]').element as HTMLElement
}

describe('MessageText memory badge click', () => {
  const opened: Array<{ memory: UserMemory }> = []
  const focus = vi.fn()

  function onOpen(event: Event) {
    opened.push((event as CustomEvent<{ memory: UserMemory }>).detail)
  }

  beforeEach(() => {
    setActivePinia(createPinia())
    opened.length = 0
    focus.mockClear()
    useMemoriesStore().memories = [memory]
    window.addEventListener('open-memory-dialog', onOpen)
  })

  afterEach(() => {
    window.removeEventListener('open-memory-dialog', onOpen)
    vi.restoreAllMocks()
  })

  async function clickBadge(memories: UserMemory[] | undefined, withFocus: boolean) {
    const wrapper = mount(MessageText, {
      props: {
        content: 'You like [Memory:42].',
        memories,
      },
      global: withFocus ? { provide: { [focusUsedMemoryKey as symbol]: focus } } : {},
    })
    await wrapper.vm.$nextTick()
    await flushPromises()
    const badge = messageTextEl(wrapper).querySelector('.memory-ref')
    expect(badge).not.toBeNull()
    badge?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    return wrapper
  }

  it('expands a memory this answer used and stays in the chat', async () => {
    await clickBadge([memory], true)
    expect(focus).toHaveBeenCalledWith(42)
    expect(opened).toEqual([])
  })

  it('opens the memories page when the answer did not list that memory', async () => {
    await clickBadge([], true)
    expect(focus).not.toHaveBeenCalled()
    expect(opened).toEqual([{ memory }])
  })
})
