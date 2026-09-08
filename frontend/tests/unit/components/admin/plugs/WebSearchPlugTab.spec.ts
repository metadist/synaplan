import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import WebSearchPlugTab from '@/components/admin/plugs/WebSearchPlugTab.vue'

const getWebSearchStatus = vi.fn()
const saveWebSearch = vi.fn()
const testWebSearch = vi.fn()
const savePlugKey = vi.fn()

vi.mock('@/services/api/adminPlugsApi', () => ({
  getWebSearchStatus: (...args: unknown[]) => getWebSearchStatus(...args),
  saveWebSearch: (...args: unknown[]) => saveWebSearch(...args),
  testWebSearch: (...args: unknown[]) => testWebSearch(...args),
  savePlugKey: (...args: unknown[]) => savePlugKey(...args),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ error: vi.fn(), success: vi.fn() }),
}))

const noneCapabilities = {
  freshness: false,
  country: false,
  language: false,
  siteFilter: false,
  fullContent: false,
  answer: false,
}

const keyStatus = {
  configured: false,
  source: 'none',
  origin: null,
  maskedKey: '',
}

describe('WebSearchPlugTab', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    getWebSearchStatus.mockResolvedValue({
      providers: [
        {
          key: 'brave',
          label: 'Brave Search',
          docsUrl: 'https://api-dashboard.search.brave.com/',
          sovereignty: 'US cloud',
          capabilities: { ...noneCapabilities, freshness: true, country: true, language: true },
          health: { available: true, reason: null },
          keyStatus,
        },
        {
          key: 'searxng',
          label: 'SearXNG',
          docsUrl: '',
          sovereignty: 'self-hosted',
          capabilities: { ...noneCapabilities, freshness: true, language: true, siteFilter: true },
          health: { available: false, reason: 'URL empty' },
          keyStatus,
        },
        {
          key: 'tavily',
          label: 'Tavily',
          docsUrl: 'https://tavily.com/',
          sovereignty: 'US cloud',
          capabilities: { ...noneCapabilities, freshness: true, fullContent: true, answer: true },
          health: { available: false, reason: 'no key' },
          keyStatus,
        },
      ],
      active: 'brave',
      fallback: '',
      userOverrideAllowed: false,
    })
  })

  it('shows the lead sentence, provider cards and Test query titles', async () => {
    testWebSearch.mockResolvedValue({
      results: [
        { title: 'Synaplan', url: 'https://synaplan.com' },
        { title: 'Docs', url: 'https://docs.synaplan.com' },
      ],
      answer: null,
      latencyMs: 12,
      error: null,
    })

    const wrapper = mount(WebSearchPlugTab, {
      global: {
        stubs: {
          Icon: true,
        },
      },
    })
    await flushPromises()

    expect(wrapper.text()).toContain('Choose who looks up the web for chat')
    expect(wrapper.get('[data-testid="web-search-card-brave"]').text()).toContain('Brave Search')
    expect(wrapper.get('[data-testid="web-search-sovereignty-searxng"]').text()).toContain(
      'self-hosted'
    )
    expect(wrapper.get('[data-testid="web-search-health-searxng"]').text()).toContain('URL empty')
    expect(wrapper.find('[data-testid="web-search-key-tavily"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="web-search-test-button"]').text()).toContain('Test query')

    await wrapper.get('[data-testid="web-search-test-query"]').setValue('synaplan open source')
    await wrapper.get('[data-testid="web-search-test-button"]').trigger('click')
    await flushPromises()

    expect(testWebSearch).toHaveBeenCalledWith('brave', 'synaplan open source')
    expect(wrapper.get('[data-testid="web-search-test-results"]').text()).toContain('Synaplan')
    expect(wrapper.get('[data-testid="web-search-test-results"]').text()).toContain('Docs')
  })
})
