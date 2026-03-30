import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import AdminSubscriptionsPanel from '@/components/admin/AdminSubscriptionsPanel.vue'

const mockSubscriptions = [
  {
    id: 1,
    name: 'Free',
    level: 'NEW',
    priceMonthly: '0.00',
    priceYearly: '0.00',
    description: 'Free plan',
    active: true,
    costBudgetMonthly: '5.00',
    costBudgetYearly: '0.00',
  },
  {
    id: 2,
    name: 'Pro',
    level: 'PRO',
    priceMonthly: '19.99',
    priceYearly: '199.00',
    description: 'Professional plan',
    active: true,
    costBudgetMonthly: '15.00',
    costBudgetYearly: '150.00',
  },
]

const { mockList, mockUpdate } = vi.hoisted(() => ({
  mockList: vi.fn(),
  mockUpdate: vi.fn(),
}))

vi.mock('@/services/api/adminSubscriptionsApi', () => ({
  adminSubscriptionsApi: {
    list: mockList,
    update: mockUpdate,
  },
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({
    success: vi.fn(),
    error: vi.fn(),
  }),
}))

const mountOptions = {
  global: {
    stubs: {
      Icon: true,
    },
  },
}

describe('AdminSubscriptionsPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockList.mockImplementation(() =>
      Promise.resolve({ success: true, subscriptions: mockSubscriptions })
    )
    mockUpdate.mockImplementation(() =>
      Promise.resolve({
        success: true,
        subscription: { ...mockSubscriptions[1], costBudgetMonthly: '20.00' },
      })
    )
  })

  it('should render loading state initially', async () => {
    mockList.mockImplementation(() => new Promise(() => {}))
    const wrapper = mount(AdminSubscriptionsPanel, mountOptions)

    expect(wrapper.find('[data-testid="loading"]').exists()).toBe(true)
  })

  it('should render subscriptions table after loading', async () => {
    const wrapper = mount(AdminSubscriptionsPanel, mountOptions)
    await flushPromises()

    expect(wrapper.find('[data-testid="loading"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="subscriptions-table"]').exists()).toBe(true)

    const rows = wrapper.findAll('tbody tr')
    expect(rows).toHaveLength(2)
  })

  it('should display subscription data correctly', async () => {
    const wrapper = mount(AdminSubscriptionsPanel, mountOptions)
    await flushPromises()

    const rows = wrapper.findAll('tbody tr')
    const firstRow = rows[0]

    expect(firstRow.text()).toContain('NEW')
    expect(firstRow.text()).toContain('Free')
    expect(firstRow.text()).toContain('0.00')
    expect(firstRow.text()).toContain('5.00')
  })

  it('should show empty state when no subscriptions', async () => {
    mockList.mockImplementation(() => Promise.resolve({ success: true, subscriptions: [] }))

    const wrapper = mount(AdminSubscriptionsPanel, mountOptions)
    await flushPromises()

    expect(wrapper.find('[data-testid="empty-state"]').exists()).toBe(true)
  })

  it('should enter edit mode on edit button click', async () => {
    const wrapper = mount(AdminSubscriptionsPanel, mountOptions)
    await flushPromises()

    await wrapper.find('[data-testid="btn-edit-2"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="input-budget-monthly"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="input-budget-yearly"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="btn-save"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="btn-cancel"]').exists()).toBe(true)
  })

  it('should cancel edit mode', async () => {
    const wrapper = mount(AdminSubscriptionsPanel, mountOptions)
    await flushPromises()

    await wrapper.find('[data-testid="btn-edit-2"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-testid="btn-cancel"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="input-budget-monthly"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="btn-edit-2"]').exists()).toBe(true)
  })

  it('should save budget changes', async () => {
    const wrapper = mount(AdminSubscriptionsPanel, mountOptions)
    await flushPromises()

    await wrapper.find('[data-testid="btn-edit-2"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-testid="btn-save"]').trigger('click')
    await flushPromises()

    expect(mockUpdate).toHaveBeenCalledWith(2, {
      costBudgetMonthly: 15,
      costBudgetYearly: 150,
    })
  })

  it('should toggle active status', async () => {
    const wrapper = mount(AdminSubscriptionsPanel, mountOptions)
    await flushPromises()

    await wrapper.find('[data-testid="toggle-active-2"]').trigger('click')
    await flushPromises()

    expect(mockUpdate).toHaveBeenCalledWith(2, { active: false })
  })
})
