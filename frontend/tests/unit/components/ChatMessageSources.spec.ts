import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ChatMessage from '@/components/ChatMessage.vue'

/**
 * Web-search result cards sit under the answer. They start folded so the
 * thread stays readable; the Sources control is the same fold button as before.
 */

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn(), resolve: () => ({ href: '#' }) }),
}))

const searchResults = [
  {
    title: 'Latest AI agent news',
    url: 'https://example.com/agents',
    description: 'A roundup of recent agent releases.',
    source: 'example.com',
  },
  {
    title: 'Second source',
    url: 'https://example.com/second',
    source: 'example.com',
  },
]

const mountMessage = () =>
  mount(ChatMessage, {
    props: {
      role: 'assistant',
      parts: [{ type: 'text', content: 'Here is a summary.' }],
      timestamp: new Date('2026-09-30T12:00:00Z'),
      searchResults,
    },
    global: {
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
  })

describe('ChatMessage web search sources', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('keeps search results folded until the Sources control is opened', async () => {
    const wrapper = mountMessage()
    const toggle = wrapper.get('[data-testid="btn-message-sources-toggle"]')
    const list = wrapper.get('[data-testid="message-sources-list"]')

    expect(toggle.attributes('aria-expanded')).toBe('false')
    // v-show keeps the list mounted. The inline display style is the fold.
    expect(list.attributes('style') ?? '').toContain('display: none')

    await toggle.trigger('click')

    expect(toggle.attributes('aria-expanded')).toBe('true')
    expect(list.attributes('style') ?? '').not.toContain('display: none')
    expect(list.text()).toContain('Latest AI agent news')
    expect(list.text()).toContain('Second source')

    await toggle.trigger('click')

    expect(toggle.attributes('aria-expanded')).toBe('false')
    expect(list.attributes('style') ?? '').toContain('display: none')
  })
})
