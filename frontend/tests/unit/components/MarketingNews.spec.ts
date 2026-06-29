import { describe, it, expect, vi, beforeEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import MarketingNews from '@/components/MarketingNews.vue'

const mockConfig = vi.hoisted(() => ({ marketingNewsEnabled: true }))
const getLandingNews = vi.hoisted(() => vi.fn())

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({
    marketingNews: {
      get enabled() {
        return mockConfig.marketingNewsEnabled
      },
    },
  }),
}))

vi.mock('@/services/api/newsApi', () => ({
  getLandingNews,
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'en' } }),
}))

vi.mock('@/composables/useDateFormat', () => ({
  useDateFormat: () => ({ formatDate: (d: Date) => d.toISOString().slice(0, 10) }),
}))

const mountOptions = {
  global: {
    stubs: { Icon: true },
    mocks: { $t: (key: string) => key },
  },
}

const sampleItems = [
  {
    title: 'Synamail inside Outlook',
    url: 'https://www.synaplan.com/blog/synamail',
    excerpt: 'Summarise threads, translate, draft replies.',
    imageUrl: 'https://www.synaplan.com/uploads/synamail.webp',
    publishedAt: '2026-06-17T00:00:00+00:00',
    tags: ['Outlook', 'Open Source'],
  },
  {
    title: 'Vibe Coding at Scale',
    url: 'https://www.synaplan.com/blog/vibe-coding',
    excerpt: 'No-shortcuts CI pipeline.',
    imageUrl: null,
    publishedAt: null,
    tags: [],
  },
]

describe('MarketingNews', () => {
  beforeEach(() => {
    mockConfig.marketingNewsEnabled = true
    getLandingNews.mockReset()
  })

  it('renders a card per fetched item when enabled', async () => {
    getLandingNews.mockResolvedValue(sampleItems)
    const wrapper = mount(MarketingNews, mountOptions)
    await flushPromises()

    expect(getLandingNews).toHaveBeenCalledWith('en')
    expect(wrapper.find('[data-testid="marketing-news"]').exists()).toBe(true)
    expect(wrapper.findAll('[data-testid="marketing-news-card"]')).toHaveLength(2)
  })

  it('renders nothing when there are no items', async () => {
    getLandingNews.mockResolvedValue([])
    const wrapper = mount(MarketingNews, mountOptions)
    await flushPromises()

    expect(wrapper.find('[data-testid="marketing-news"]').exists()).toBe(false)
  })

  it('does not fetch when the master switch is disabled', async () => {
    mockConfig.marketingNewsEnabled = false
    getLandingNews.mockResolvedValue(sampleItems)
    const wrapper = mount(MarketingNews, mountOptions)
    await flushPromises()

    expect(getLandingNews).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="marketing-news"]').exists()).toBe(false)
  })

  it('renders nothing when the fetch fails', async () => {
    getLandingNews.mockRejectedValue(new Error('network'))
    const wrapper = mount(MarketingNews, mountOptions)
    await flushPromises()

    expect(wrapper.find('[data-testid="marketing-news"]').exists()).toBe(false)
  })
})
