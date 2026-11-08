import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ApprovalsInbox from '@/components/config/ApprovalsInbox.vue'

const { mockList, mockGetOne, mockApprove, mockReject, mockGetNotify, mockSetNotify } = vi.hoisted(
  () => ({
    mockList: vi.fn(),
    mockGetOne: vi.fn(),
    mockApprove: vi.fn(),
    mockReject: vi.fn(),
    mockGetNotify: vi.fn(),
    mockSetNotify: vi.fn(),
  })
)

vi.mock('@/services/api/approvalsApi', () => ({
  approvalsApi: {
    list: mockList,
    get: mockGetOne,
    approve: mockApprove,
    reject: mockReject,
    getNotifyMode: mockGetNotify,
    setNotifyMode: mockSetNotify,
  },
}))

vi.mock('@/composables/useApprovalsFeature', () => ({
  isApprovalsEnabled: () => true,
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: vi.fn(), error: vi.fn() }),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ prompt: vi.fn() }),
}))

describe('ApprovalsInbox', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    mockList.mockResolvedValue({ pendingCount: 0, approvals: [] })
    mockGetNotify.mockResolvedValue({ mode: 'instant', mailConfigured: true })
  })

  it('shows the empty-state next action when nothing is pending', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/channels/approvals', component: { template: '<div />' } }],
    })
    await router.push('/channels/approvals')
    const wrapper = mount(ApprovalsInbox, {
      global: { plugins: [router], stubs: { Icon: true } },
    })
    await flushPromises()
    expect(wrapper.get('[data-testid="approvals-empty"]').text()).toContain(
      'Nothing needs your approval'
    )
  })

  it('opens the decided list when the audit link names an approval', async () => {
    const decided = {
      id: 812,
      tool: 'create_issue',
      preview: 'Create the issue',
      status: 'executed',
      expiresAt: 1,
      created: 1,
      decidedAt: 2,
      decidedByName: 'Ada',
      requestedBy: { kind: 'chat' as const },
      sideEffect: 'write' as const,
      canAlwaysAllow: false,
    }
    mockList.mockResolvedValue({ pendingCount: 0, approvals: [] })
    mockGetOne.mockResolvedValue(decided)
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/approvals', component: { template: '<div />' } }],
    })
    await router.push('/approvals?approval=812')
    const wrapper = mount(ApprovalsInbox, {
      global: { plugins: [router], stubs: { Icon: true } },
    })
    await flushPromises()
    expect(wrapper.get('[data-testid="approval-row-812"]').text()).toContain('Create the issue')
    expect(wrapper.get('[data-testid="approvals-tab-decided"]').classes()).toContain('btn-primary')
  })
})
