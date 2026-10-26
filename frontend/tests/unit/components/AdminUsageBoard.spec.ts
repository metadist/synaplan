import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { asI18nSchema, loadAllMessages } from '@/i18n/loadAllMessages'

const en = loadAllMessages('en')
const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: asI18nSchema(en) } })

const { mockUsers, mockRequests } = vi.hoisted(() => ({
  mockUsers: vi.fn(),
  mockRequests: vi.fn(),
}))

vi.mock('@/services/api/adminUsageApi', () => ({
  adminUsageApi: {
    users: mockUsers,
    requests: mockRequests,
  },
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn() }),
}))

import AdminUsageView from '@/views/AdminUsageView.vue'
import UsageRequestsPanel from '@/components/admin/usage/UsageRequestsPanel.vue'

const userRow = {
  userId: 42,
  email: 'ada@example.com',
  displayName: 'Ada Example',
  level: 'PRO',
  accountStatus: 'active',
  requests: 12,
  tokens: 6000,
  promptTokens: 5000,
  completionTokens: 1000,
  cost: 3.5,
  lastActiveAt: 1792997403,
  byKind: [
    { kind: 'agent' as const, requests: 2, tokens: 500, cost: 2 },
    { kind: 'chat' as const, requests: 8, tokens: 5000, cost: 1 },
    { kind: 'video' as const, requests: 2, tokens: 500, cost: 0.5 },
  ],
}

const requestRow = {
  id: 9,
  at: 1792997403,
  kind: 'chat' as const,
  action: 'MESSAGES',
  channel: 'WEB',
  provider: 'anthropic',
  model: 'claude-sonnet-5',
  tokens: 100,
  promptTokens: 80,
  completionTokens: 20,
  cachedTokens: 0,
  cost: 0.01,
  latencyMs: 900,
  status: 'success',
  estimated: false,
  chatId: 12,
  agentId: null,
}

function mountView() {
  return mount(AdminUsageView, {
    global: {
      plugins: [i18n],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        PageHeader: { template: '<div />', props: ['title', 'subtitle', 'icon'] },
        Icon: true,
      },
    },
  })
}

describe('AdminUsageView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockUsers.mockResolvedValue({
      success: true,
      users: [userRow],
      totalUsers: 21,
      page: 1,
      perPage: 20,
      totalPages: 2,
      period: '30d',
      sort: 'cost',
    })
    mockRequests.mockResolvedValue({
      success: true,
      requests: [requestRow],
      total: 1,
      page: 1,
      perPage: 20,
      totalPages: 1,
      period: '30d',
    })
  })

  it('shows the hit parade with the kind split and paginates', async () => {
    const wrapper = mountView()
    await flushPromises()

    const row = wrapper.get('[data-testid="usage-user-row"]')
    expect(row.text()).toContain('Ada Example')
    expect(row.text()).toContain('ada@example.com')
    expect(row.text()).toContain('Agent')
    expect(row.text()).toContain('Video')
    expect(wrapper.text()).toContain('21 users with model use')

    await wrapper.get('[data-testid="usage-next"]').trigger('click')
    await flushPromises()
    expect(mockUsers).toHaveBeenLastCalledWith(
      expect.objectContaining({ period: '30d', sort: 'cost', page: 2, perPage: 20 })
    )
  })

  it('expands a row into the individual model requests', async () => {
    const wrapper = mountView()
    await flushPromises()

    await wrapper.get('[data-testid="usage-user-toggle-42"]').trigger('click')
    await flushPromises()

    const panel = wrapper.get('[data-testid="usage-requests-panel"]')
    expect(panel.text()).toContain('claude-sonnet-5')
    expect(panel.text()).toContain('anthropic')
    expect(mockRequests).toHaveBeenCalledWith(
      42,
      expect.objectContaining({ period: '30d', page: 1 })
    )
  })

  it('marks the selected period for screen readers', async () => {
    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.get('[data-testid="usage-period-30d"]').attributes('aria-pressed')).toBe('true')
    expect(wrapper.get('[data-testid="usage-period-24h"]').attributes('aria-pressed')).toBe('false')
  })

  it('keeps the newest filter when an older load answers last', async () => {
    let answerSlowLoad: (value: unknown) => void = () => {}
    const slowLoad = new Promise((resolve) => {
      answerSlowLoad = resolve
    })
    mockUsers.mockReturnValueOnce(slowLoad)
    const wrapper = mountView()

    mockUsers.mockResolvedValueOnce({
      success: true,
      users: [{ ...userRow, displayName: 'Newest Filter' }],
      totalUsers: 1,
      page: 1,
      perPage: 20,
      totalPages: 1,
      period: '24h',
      sort: 'cost',
    })
    await wrapper.get('[data-testid="usage-period-24h"]').trigger('click')
    await flushPromises()
    answerSlowLoad({
      success: true,
      users: [{ ...userRow, displayName: 'Stale Thirty Days' }],
      totalUsers: 21,
      page: 1,
      perPage: 20,
      totalPages: 2,
      period: '30d',
      sort: 'cost',
    })
    await flushPromises()

    expect(wrapper.text()).toContain('Newest Filter')
    expect(wrapper.text()).not.toContain('Stale Thirty Days')
  })

  it('offers the all-time view from the empty state', async () => {
    mockUsers.mockResolvedValue({
      success: true,
      users: [],
      totalUsers: 0,
      page: 1,
      perPage: 20,
      totalPages: 1,
      period: '30d',
      sort: 'cost',
    })
    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.find('[data-testid="usage-empty"]').exists()).toBe(true)
    await wrapper.get('[data-testid="usage-empty-all-time"]').trigger('click')
    await flushPromises()
    expect(mockUsers).toHaveBeenLastCalledWith(expect.objectContaining({ period: 'all', page: 1 }))
  })
})

describe('UsageRequestsPanel', () => {
  it('renders one row per request without any content fields', async () => {
    mockRequests.mockResolvedValue({
      success: true,
      requests: [requestRow],
      total: 1,
      page: 1,
      perPage: 20,
      totalPages: 1,
      period: '30d',
    })
    const wrapper = mount(UsageRequestsPanel, {
      props: { userId: 42, period: '30d' },
      global: { plugins: [i18n] },
    })
    await flushPromises()

    const rows = wrapper.findAll('[data-testid="usage-request-row"]')
    expect(rows).toHaveLength(1)
    expect(rows[0].text()).toContain('Chat')
    expect(rows[0].text()).toContain('900 ms')
  })

  it('shows a sub-cent request cost instead of $0.00', async () => {
    mockRequests.mockResolvedValue({
      success: true,
      requests: [{ ...requestRow, cost: 0.004 }],
      total: 1,
      page: 1,
      perPage: 20,
      totalPages: 1,
      period: '30d',
    })
    const wrapper = mount(UsageRequestsPanel, {
      props: { userId: 42, period: '30d' },
      global: { plugins: [i18n] },
    })
    await flushPromises()

    expect(wrapper.get('[data-testid="usage-request-row"]').text()).toContain('$0.004')
  })
})
