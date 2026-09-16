import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import MessageText from '@/components/MessageText.vue'
import { useMessageDigestsStore } from '@/stores/messageDigests'

// [Message:ID] deep-memory badges (Sprint 4): a reference delivered via the
// digests_loaded SSE event (or the resolve endpoint) renders as a clickable
// badge that navigates to the source chat; an invented id must never become
// a clickable badge.

vi.mock('@/services/api/messageDigestsApi', () => ({
  resolveMessageDigests: vi.fn().mockResolvedValue([]),
}))

function messageTextEl(wrapper: ReturnType<typeof mount>): HTMLElement {
  return wrapper.get('[data-testid="message-text"]').element as HTMLElement
}

const rentLetter = {
  messageId: 1234,
  chatId: 42,
  title: 'office rent letter to realtor about the increase of payments',
  channel: 'web',
  sourceDate: 1747216800,
}

describe('MessageText [Message:ID] badges', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('renders a delivered reference as a clickable badge with the digest title', async () => {
    const store = useMessageDigestsStore()
    store.addReferences([rentLetter])

    const wrapper = mount(MessageText, {
      props: { content: 'See [Message:1234] for the letter.' },
    })
    await wrapper.vm.$nextTick()

    const el = messageTextEl(wrapper)
    const badge = el.querySelector('.message-ref[data-digest-message-id="1234"]')
    expect(badge).not.toBeNull()
    expect(badge?.getAttribute('data-digest-chat-id')).toBe('42')
    expect(el.textContent).toContain('office rent letter to realtor')
    // The raw tag must not leak into the visible text.
    expect(el.textContent).not.toContain('[Message:1234]')
  })

  it('clicking the badge asks ChatView to open the source chat', async () => {
    const store = useMessageDigestsStore()
    store.addReferences([rentLetter])

    const wrapper = mount(MessageText, {
      props: { content: 'See [Message:1234].' },
    })
    await wrapper.vm.$nextTick()

    const opened: CustomEvent[] = []
    const onOpen = (event: Event) => {
      opened.push(event as CustomEvent)
    }
    window.addEventListener('open-message-reference', onOpen)
    try {
      const badge = messageTextEl(wrapper).querySelector(
        '.message-ref[data-digest-message-id="1234"]'
      )
      expect(badge).not.toBeNull()
      badge?.dispatchEvent(new MouseEvent('click', { bubbles: true }))

      expect(opened).toHaveLength(1)
      expect(opened[0].detail).toEqual({ messageId: 1234, chatId: 42 })
    } finally {
      window.removeEventListener('open-message-reference', onOpen)
    }
  })

  it('an invented id renders a muted non-navigating badge, never a clickable one', async () => {
    const store = useMessageDigestsStore()
    // Backend said this id does not exist (invented / deleted / foreign).
    store.addReferences([rentLetter])
    store.unresolvable.add(99999)

    const wrapper = mount(MessageText, {
      props: { content: 'Ref [Message:99999].' },
    })
    await wrapper.vm.$nextTick()

    const el = messageTextEl(wrapper)
    expect(el.querySelector('.message-ref[data-digest-message-id]')).toBeNull()
    const missing = el.querySelector('.message-ref--missing')
    expect(missing).not.toBeNull()

    const opened: Event[] = []
    const onOpen = (event: Event) => {
      opened.push(event)
    }
    window.addEventListener('open-message-reference', onOpen)
    try {
      missing?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
      expect(opened).toHaveLength(0)
    } finally {
      window.removeEventListener('open-message-reference', onOpen)
    }
  })

  it('resolves badges while still streaming, like memory and feedback refs', async () => {
    const store = useMessageDigestsStore()
    store.addReferences([rentLetter])

    const wrapper = mount(MessageText, {
      props: { content: 'See [Message:1234] for the', isStreaming: true },
    })
    await wrapper.vm.$nextTick()

    const el = messageTextEl(wrapper)
    expect(el.querySelector('.message-ref[data-digest-message-id="1234"]')).not.toBeNull()
    expect(el.textContent).not.toContain('[Message:1234]')
  })

  it('readonly (shared) views render a neutral label without navigation', async () => {
    const wrapper = mount(MessageText, {
      props: { content: 'See [Message:1234].', readonly: true },
    })
    await wrapper.vm.$nextTick()

    const el = messageTextEl(wrapper)
    expect(el.querySelector('.message-ref--readonly')).not.toBeNull()
    expect(el.querySelector('[data-digest-message-id]')).toBeNull()
    expect(el.textContent).not.toContain('[Message:1234]')
  })
})
