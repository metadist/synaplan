import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ChatMessage from '@/components/ChatMessage.vue'
import type { Part } from '@/stores/history'

/**
 * An assistant turn can finish with nothing to show — a media-only turn, or a
 * stream that completes without a single text chunk. The copy button used to
 * render on the role alone, so it sat by itself in an empty bubble offering an
 * action its own handler already refused to perform. On touch layouts it never
 * fades out, which is where it was reported from.
 */

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn(), resolve: () => ({ href: '#' }) }),
}))

const mountOptions = {
  global: {
    mocks: {
      $t: (key: string) => key,
    },
    stubs: {
      RouterLink: true,
      Icon: true,
      MessagePart: true,
      MessageMemories: true,
      MessageFeedbacks: true,
      ServiceIcon: true,
      ModelCostBadge: true,
      ToolBadge: true,
      TaskPlanBubble: true,
      MediaJobStatus: true,
      ExternalLinkWarning: true,
    },
  },
}

function hasCopyButton(parts: Part[], { role = 'assistant', isStreaming = false } = {}): boolean {
  const wrapper = mount(ChatMessage, {
    ...mountOptions,
    props: { role: role as 'assistant' | 'user', parts, timestamp: new Date(), isStreaming },
  })
  return wrapper.find('[data-testid="btn-message-copy"]').exists()
}

describe('ChatMessage copy button', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('is offered when the finished turn has text to copy', () => {
    expect(hasCopyButton([{ type: 'text', content: 'Here is your answer.' }])).toBe(true)
  })

  it('is gone when the finished turn has no content at all', () => {
    expect(hasCopyButton([])).toBe(false)
  })

  it('is gone when the only content is blank', () => {
    expect(hasCopyButton([{ type: 'text', content: '   \n  ' }])).toBe(false)
  })

  it('is gone when the turn carries nothing but hidden reasoning', () => {
    // `thinking` parts are excluded from the clipboard, so they are not content.
    expect(hasCopyButton([{ type: 'thinking', content: 'weighing the options' }])).toBe(false)
  })

  it('stays mounted through an empty stream so the bubble does not jump', () => {
    // It is invisible while streaming; keeping it mounted avoids the layout pop
    // when the first token arrives.
    expect(hasCopyButton([], { isStreaming: true })).toBe(true)
  })

  it('is never offered on a user message', () => {
    expect(hasCopyButton([{ type: 'text', content: 'my question' }], { role: 'user' })).toBe(false)
  })
})
