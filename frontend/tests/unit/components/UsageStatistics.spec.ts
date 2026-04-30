import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import UsageStatistics from '@/components/config/UsageStatistics.vue'

const mockStats = {
  user_level: 'PRO',
  phone_verified: true,
  subscription: {
    level: 'PRO',
    active: true,
    status: 'active',
    plan_name: 'Pro Plan',
    expires_at: null,
    stripe_customer_id: null,
  },
  usage: {
    messages: {
      used: 50,
      limit: 1000,
      remaining: 950,
      allowed: true,
      resets_at: null,
      type: 'lifetime',
    },
  },
  limits: {},
  remaining: {},
  breakdown: {
    by_source: {
      WEB: { total: 30, actions: { messages: 30 } },
    },
    by_time: {
      today: { total: 5, actions: { messages: 5 } },
    },
  },
  recent_usage: [
    {
      timestamp: Math.floor(Date.now() / 1000) - 120,
      datetime: '2025-06-01 10:00:00',
      action: 'messages',
      source: 'WEB',
      model: 'gpt-4o',
      tokens: 1500,
      prompt_tokens: 1000,
      completion_tokens: 500,
      cached_tokens: 200,
      cache_creation_tokens: 0,
      estimated: false,
      cost: 0.0105,
      latency: 2500,
      status: 'success',
    },
    {
      timestamp: Math.floor(Date.now() / 1000) - 3600,
      datetime: '2025-06-01 09:00:00',
      action: 'messages',
      source: 'WEB',
      model: 'claude-3-sonnet',
      tokens: 800,
      prompt_tokens: 500,
      completion_tokens: 300,
      cached_tokens: 0,
      cache_creation_tokens: 0,
      estimated: true,
      cost: 0,
      latency: 0,
      status: 'success',
    },
  ],
  total_requests: 50,
  total_messages: 50,
  cost_budget: {
    used: 5.25,
    budget: 15.0,
    remaining: 9.75,
    percent: 35.0,
    period_start: Math.floor(Date.now() / 1000) - 86400 * 15,
    period_end: Math.floor(Date.now() / 1000) + 86400 * 15,
  },
  cost_summary: {
    today: 0.0523,
    this_week: 1.234,
    this_month: 5.25,
    cache_savings: 0.15,
  },
}

vi.mock('@/services/authService', () => ({
  authService: {
    isAuthenticated: () => true,
  },
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({
    success: vi.fn(),
    error: vi.fn(),
  }),
}))

const mockActivityResponse = {
  items: mockStats.recent_usage,
  total: mockStats.recent_usage.length,
  page: 1,
  per_page: 20,
  total_pages: 1,
}

vi.mock('@/api/usageApi', () => ({
  getUsageStats: vi.fn(() => Promise.resolve(mockStats)),
  getActivityLog: vi.fn(() => Promise.resolve(mockActivityResponse)),
  downloadUsageExport: vi.fn(() => Promise.resolve()),
}))

const mountOptions = {
  global: {
    stubs: {
      Icon: true,
    },
  },
}

describe('UsageStatistics', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('should render header and export button', async () => {
    const wrapper = mount(UsageStatistics, mountOptions)
    await flushPromises()

    expect(wrapper.find('[data-testid="section-header"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="btn-export"]').exists()).toBe(true)
  })

  it('should render stats after loading', async () => {
    const wrapper = mount(UsageStatistics, mountOptions)
    await flushPromises()

    expect(wrapper.find('[data-testid="section-loading"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="section-stats"]').exists()).toBe(true)
  })

  it('should display cost budget section with progress bar', async () => {
    const wrapper = mount(UsageStatistics, mountOptions)
    await flushPromises()

    const budgetSection = wrapper.find('[data-testid="section-cost-budget"]')
    expect(budgetSection.exists()).toBe(true)
    expect(budgetSection.text()).toContain('35.0%')
  })

  it('should display cost summary cards', async () => {
    const wrapper = mount(UsageStatistics, mountOptions)
    await flushPromises()

    const summarySection = wrapper.find('[data-testid="section-cost-summary"]')
    expect(summarySection.exists()).toBe(true)
    expect(summarySection.text()).toContain('$0.0523')
    expect(summarySection.text()).toContain('$1.2340')
    expect(summarySection.text()).toContain('$5.2500')
  })

  it('should display recent activity with token breakdown', async () => {
    const wrapper = mount(UsageStatistics, mountOptions)
    await flushPromises()

    const activityRows = wrapper.findAll('[data-testid="item-activity"]')
    expect(activityRows.length).toBe(2)

    const firstRow = activityRows[0]
    expect(firstRow.text()).toContain('gpt-4o')
    expect(firstRow.text()).toContain('1,000')
    expect(firstRow.text()).toContain('500')
    expect(firstRow.text()).toContain('200')
    expect(firstRow.text()).toContain('$0.0105')
  })

  it('should show estimated badge for estimated token counts', async () => {
    const wrapper = mount(UsageStatistics, mountOptions)
    await flushPromises()

    const activityRows = wrapper.findAll('[data-testid="item-activity"]')
    const secondRow = activityRows[1]
    expect(secondRow.text()).toContain('~')
  })

  it('should show dash for zero cached tokens', async () => {
    const wrapper = mount(UsageStatistics, mountOptions)
    await flushPromises()

    const activityRows = wrapper.findAll('[data-testid="item-activity"]')
    const secondRow = activityRows[1]
    expect(secondRow.text()).toContain('-')
  })

  it('should display subscription info', async () => {
    const wrapper = mount(UsageStatistics, mountOptions)
    await flushPromises()

    const subscriptionSection = wrapper.find('[data-testid="section-subscription"]')
    expect(subscriptionSection.exists()).toBe(true)
    expect(subscriptionSection.text()).toContain('Pro Plan')
  })

  it('should display empty state when no recent activity', async () => {
    const { getActivityLog } = await import('@/api/usageApi')
    vi.mocked(getActivityLog).mockResolvedValueOnce({
      items: [],
      total: 0,
      page: 1,
      per_page: 20,
      total_pages: 1,
    })

    const wrapper = mount(UsageStatistics, mountOptions)
    await flushPromises()

    expect(wrapper.find('[data-testid="row-empty"]').exists()).toBe(true)
  })

  it('should apply correct color class to budget bar based on percent', async () => {
    const wrapper = mount(UsageStatistics, mountOptions)
    await flushPromises()

    const budgetBar = wrapper.find('[data-testid="section-cost-budget"]')
    expect(budgetBar.html()).toContain('bg-green-500')
  })
})
