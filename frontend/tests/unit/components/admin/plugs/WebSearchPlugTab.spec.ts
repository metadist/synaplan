import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import WebSearchPlugTab from '@/components/admin/plugs/WebSearchPlugTab.vue'

const getWebSearchStatus = vi.fn()
const saveWebSearch = vi.fn()
const testWebSearch = vi.fn()
const savePlugKey = vi.fn()
const { success, warning, showError } = vi.hoisted(() => ({
  success: vi.fn(),
  warning: vi.fn(),
  showError: vi.fn(),
}))

vi.mock('@/services/api/adminPlugsApi', () => ({
  getWebSearchStatus: (...args: unknown[]) => getWebSearchStatus(...args),
  saveWebSearch: (...args: unknown[]) => saveWebSearch(...args),
  testWebSearch: (...args: unknown[]) => testWebSearch(...args),
  savePlugKey: (...args: unknown[]) => savePlugKey(...args),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ error: showError, success, warning }),
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
    success.mockClear()
    warning.mockClear()
    showError.mockClear()
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
      provider: 'brave',
      fellBackFrom: null,
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
    expect(wrapper.get('[data-testid="web-search-test-provider"]').text()).toContain(
      'Answered by brave'
    )
  })

  it('does not show Answered by when the test query fails', async () => {
    testWebSearch.mockResolvedValue({
      results: [],
      answer: null,
      latencyMs: 9,
      error: 'Brave search returned HTTP 401',
      provider: 'brave',
      fellBackFrom: null,
    })

    const wrapper = mount(WebSearchPlugTab, {
      global: {
        stubs: {
          Icon: true,
        },
      },
    })
    await flushPromises()
    await wrapper.get('[data-testid="web-search-test-query"]').setValue('synaplan')
    await wrapper.get('[data-testid="web-search-test-button"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="web-search-test-provider"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="web-search-test-results"]').text()).toContain('HTTP 401')
    expect(wrapper.get('[data-testid="web-search-test-results"]').text()).not.toContain(
      'Answered by'
    )
  })

  it('warns when the saved active provider cannot search', async () => {
    saveWebSearch.mockResolvedValue({
      providers: [
        {
          key: 'exa',
          label: 'Exa',
          docsUrl: '',
          sovereignty: 'US cloud',
          capabilities: noneCapabilities,
          health: { available: false, reason: 'Exa API key is not configured' },
          keyStatus,
        },
      ],
      active: 'exa',
      fallback: '',
      userOverrideAllowed: false,
    })

    const wrapper = mount(WebSearchPlugTab, {
      global: {
        stubs: {
          Icon: true,
        },
      },
    })
    await flushPromises()
    await wrapper.get('[data-testid="web-search-save"]').trigger('click')
    await flushPromises()

    expect(warning).toHaveBeenCalledWith(
      expect.stringContaining('Chat web search will return nothing')
    )
    expect(success).not.toHaveBeenCalled()
  })

  it('keeps an unverified key out of the card when save is rejected', async () => {
    savePlugKey.mockRejectedValue(new Error('API key was not stored: Exa search returned HTTP 401'))

    const wrapper = mount(WebSearchPlugTab, {
      global: {
        stubs: {
          Icon: true,
        },
      },
    })
    await flushPromises()
    await wrapper.get('[data-testid="web-search-key-tavily"]').setValue('not-a-real-key')
    await wrapper.get('[data-testid="web-search-save-key-tavily"]').trigger('click')
    await flushPromises()

    expect(showError).toHaveBeenCalledWith('API key was not stored: Exa search returned HTTP 401')
    expect(success).not.toHaveBeenCalled()
    expect(
      (wrapper.get('[data-testid="web-search-key-tavily"]').element as HTMLInputElement).value
    ).toBe('not-a-real-key')
  })

  it('still reports the key as saved when status refresh fails', async () => {
    savePlugKey.mockResolvedValue({
      configured: true,
      source: 'db',
      origin: 'ui',
      maskedKey: '••••abcd',
    })

    const wrapper = mount(WebSearchPlugTab, {
      global: {
        stubs: {
          Icon: true,
        },
      },
    })
    await flushPromises()

    getWebSearchStatus.mockRejectedValueOnce(new Error('status down'))
    await wrapper.get('[data-testid="web-search-key-tavily"]').setValue('real-key')
    await wrapper.get('[data-testid="web-search-save-key-tavily"]').trigger('click')
    await flushPromises()

    expect(success).toHaveBeenCalledWith('Key saved and verified. The next search can use it.')
    expect(showError).not.toHaveBeenCalled()
  })

  it('warns when the saved active provider cannot search', async () => {
    saveWebSearch.mockResolvedValue({
      providers: [
        {
          key: 'exa',
          label: 'Exa',
          docsUrl: '',
          sovereignty: 'US cloud',
          capabilities: noneCapabilities,
          health: { available: false, reason: 'Exa API key is not configured' },
          keyStatus,
        },
      ],
      active: 'exa',
      fallback: '',
      userOverrideAllowed: false,
    })

    const wrapper = mount(WebSearchPlugTab, {
      global: {
        stubs: {
          Icon: true,
        },
      },
    })
    await flushPromises()
    await wrapper.get('[data-testid="web-search-save"]').trigger('click')
    await flushPromises()

    expect(warning).toHaveBeenCalledWith(
      expect.stringContaining('Chat web search will return nothing')
    )
    expect(success).not.toHaveBeenCalled()
  })

  it('keeps an unverified key out of the card when save is rejected', async () => {
    savePlugKey.mockRejectedValue(new Error('API key was not stored: Exa search returned HTTP 401'))

    const wrapper = mount(WebSearchPlugTab, {
      global: {
        stubs: {
          Icon: true,
        },
      },
    })
    await flushPromises()
    await wrapper.get('[data-testid="web-search-key-tavily"]').setValue('not-a-real-key')
    await wrapper.get('[data-testid="web-search-save-key-tavily"]').trigger('click')
    await flushPromises()

    expect(showError).toHaveBeenCalledWith('API key was not stored: Exa search returned HTTP 401')
    expect(success).not.toHaveBeenCalled()
    expect(
      (wrapper.get('[data-testid="web-search-key-tavily"]').element as HTMLInputElement).value
    ).toBe('not-a-real-key')
  })

  it('still reports the key as saved when status refresh fails', async () => {
    savePlugKey.mockResolvedValue({
      configured: true,
      source: 'db',
      origin: 'ui',
      maskedKey: '••••abcd',
    })

    const wrapper = mount(WebSearchPlugTab, {
      global: {
        stubs: {
          Icon: true,
        },
      },
    })
    await flushPromises()

    getWebSearchStatus.mockRejectedValueOnce(new Error('status down'))
    await wrapper.get('[data-testid="web-search-key-tavily"]').setValue('real-key')
    await wrapper.get('[data-testid="web-search-save-key-tavily"]').trigger('click')
    await flushPromises()

    expect(success).toHaveBeenCalledWith('Key saved and verified. The next search can use it.')
    expect(showError).not.toHaveBeenCalled()
  })
})
