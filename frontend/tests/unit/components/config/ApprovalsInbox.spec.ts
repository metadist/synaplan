import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ApprovalsInbox from '@/components/config/ApprovalsInbox.vue'

const { mockList, mockApprove, mockReject, mockGetNotify, mockSetNotify } = vi.hoisted(() => ({
  mockList: vi.fn(),
  mockApprove: vi.fn(),
  mockReject: vi.fn(),
  mockGetNotify: vi.fn(),
  mockSetNotify: vi.fn(),
}))

vi.mock('@/services/api/approvalsApi', () => ({
  approvalsApi: {
    list: mockList,
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
    mockGetNotify.mockResolvedValue('instant')
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
})
