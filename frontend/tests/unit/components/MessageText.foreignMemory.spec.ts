import { describe, it, expect, beforeEach, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import MessageText from '@/components/MessageText.vue'
import { useMemoriesStore } from '@/stores/userMemories'

// [Memory:ID] in a conversation received through a group belongs to the chat
// owner. The viewer must see a terminal badge immediately — no spinner, no
// user-scoped lookup, no "memory not found" copy (issue #1880).

function messageTextEl(wrapper: ReturnType<typeof mount>): HTMLElement {
  return wrapper.get('[data-testid="message-text"]').element as HTMLElement
}

describe('MessageText foreign [Memory:ID] badges', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('renders a terminal owner badge and does not look the memory up', async () => {
    const store = useMemoriesStore()
    const fetchById = vi.spyOn(store, 'fetchMemoryById')
    const fetchAll = vi.spyOn(store, 'fetchMemories')

    const wrapper = mount(MessageText, {
      props: {
        content: 'after work [Memory:1785496054423666]?',
        foreignMemory: true,
      },
    })
    await wrapper.vm.$nextTick()
    await flushPromises()

    const el = messageTextEl(wrapper)
    expect(el.querySelector('.memory-ref--foreign')).not.toBeNull()
    expect(el.querySelector('.animate-spin')).toBeNull()
    expect(el.querySelector('a.memory-ref--readonly')).toBeNull()
    expect(el.querySelector('.memory-ref--missing')).toBeNull()
    expect(el.textContent).not.toContain('[Memory:')
    expect(el.textContent).toContain('Private memory')
    expect(fetchById).not.toHaveBeenCalled()
    expect(fetchAll).not.toHaveBeenCalled()
  })
})
