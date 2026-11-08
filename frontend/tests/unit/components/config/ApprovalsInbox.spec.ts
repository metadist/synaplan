import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ApprovalsInbox from '@/components/config/ApprovalsInbox.vue'
import { useApprovalsStore } from '@/stores/approvals'

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

  it('puts a linked approval first even when it is already in the list', async () => {
    const older = {
      id: 1,
      tool: 'other',
      preview: 'Older decision',
      status: 'executed',
      expiresAt: 1,
      created: 1,
      requestedBy: { kind: 'chat' as const },
      sideEffect: 'write' as const,
      canAlwaysAllow: false,
    }
    const linked = {
      id: 812,
      tool: 'create_issue',
      preview: 'From the list',
      status: 'executed',
      expiresAt: 1,
      created: 1,
      requestedBy: { kind: 'chat' as const },
      sideEffect: 'write' as const,
      canAlwaysAllow: false,
    }
    mockList.mockImplementation(async (status: string) => {
      if (status === 'decided') return { pendingCount: 0, approvals: [older, linked] }
      return { pendingCount: 0, approvals: [] }
    })
    mockGetOne.mockResolvedValue({ ...linked, preview: null, status: 'approved' })
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/approvals', component: { template: '<div />' } }],
    })
    await router.push('/approvals?approval=812')
    const wrapper = mount(ApprovalsInbox, {
      global: { plugins: [router], stubs: { Icon: true } },
    })
    await flushPromises()
    const rows = wrapper.findAll('[data-testid^="approval-row-"]')
    expect(rows[0]?.attributes('data-testid')).toBe('approval-row-812')
    expect(rows[0]?.text()).toContain('From the list')
    expect(rows).toHaveLength(2)
  })

  it('loads a new approval when the query changes without remounting', async () => {
    const linked = {
      id: 812,
      tool: 'create_issue',
      preview: 'Create the issue',
      status: 'executed',
      expiresAt: 1,
      created: 1,
      requestedBy: { kind: 'chat' as const },
      sideEffect: 'write' as const,
      canAlwaysAllow: false,
    }
    mockGetOne.mockResolvedValue(linked)
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/approvals', component: { template: '<div />' } }],
    })
    await router.push('/approvals')
    const wrapper = mount(ApprovalsInbox, {
      global: { plugins: [router], stubs: { Icon: true } },
    })
    await flushPromises()
    expect(wrapper.find('[data-testid="approval-row-812"]').exists()).toBe(false)
    await router.push('/approvals?approval=812')
    await flushPromises()
    expect(wrapper.get('[data-testid="approval-row-812"]').text()).toContain('Create the issue')
  })

  it('returns to the pending list when the approval query is cleared', async () => {
    const pending = {
      id: 4,
      tool: 'memory_add',
      preview: 'Still waiting',
      status: 'pending',
      expiresAt: 1,
      created: 1,
      requestedBy: { kind: 'chat' as const },
      sideEffect: 'write' as const,
      canAlwaysAllow: false,
    }
    const decided = {
      id: 812,
      tool: 'create_issue',
      preview: 'Create the issue',
      status: 'executed',
      expiresAt: 1,
      created: 1,
      requestedBy: { kind: 'chat' as const },
      sideEffect: 'write' as const,
      canAlwaysAllow: false,
    }
    mockList.mockImplementation(async (status: string) => {
      if (status === 'pending') return { pendingCount: 1, approvals: [pending] }
      return { pendingCount: 1, approvals: [decided] }
    })
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
    expect(wrapper.get('[data-testid="approvals-tab-decided"]').classes()).toContain('btn-primary')
    await router.push('/approvals')
    await flushPromises()
    expect(wrapper.get('[data-testid="approvals-tab-pending"]').classes()).toContain('btn-primary')
    expect(wrapper.get('[data-testid="approval-row-4"]').text()).toContain('Still waiting')
    expect(wrapper.find('[data-testid="approval-row-812"]').exists()).toBe(false)
  })

  it('drops a pending card once that approval is on the decided list', async () => {
    const pending = {
      id: 4,
      tool: 'memory_add',
      preview: 'Still waiting',
      status: 'pending',
      expiresAt: 1,
      created: 1,
      requestedBy: { kind: 'chat' as const },
      sideEffect: 'write' as const,
      canAlwaysAllow: true,
    }
    const decided = {
      ...pending,
      status: 'executed',
      preview: 'Already decided',
      canAlwaysAllow: false,
    }
    let sawDecided = false
    mockList.mockImplementation(async (status: string) => {
      if (status === 'decided') {
        sawDecided = true
        return { pendingCount: 0, approvals: [decided] }
      }
      if (sawDecided) return { pendingCount: 0, approvals: [pending] }
      return { pendingCount: 1, approvals: [pending] }
    })
    mockGetOne.mockResolvedValue(pending)
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/approvals', component: { template: '<div />' } }],
    })
    await router.push('/approvals?approval=4')
    const wrapper = mount(ApprovalsInbox, {
      global: { plugins: [router], stubs: { Icon: true } },
    })
    await flushPromises()
    expect(wrapper.get('[data-testid="approval-row-4"]').text()).toContain('Still waiting')
    await wrapper.get('[data-testid="approvals-tab-decided"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-testid="approval-row-4"]').text()).toContain('Already decided')
    await wrapper.get('[data-testid="approvals-tab-pending"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="approval-row-4"]').exists()).toBe(false)
  })

  it('keeps a pending approval that is older than the pending page', async () => {
    const pending = {
      id: 4,
      tool: 'memory_add',
      preview: 'Still waiting',
      status: 'pending',
      expiresAt: 1,
      created: 1,
      requestedBy: { kind: 'chat' as const },
      sideEffect: 'write' as const,
      canAlwaysAllow: true,
    }
    mockGetOne.mockResolvedValue(pending)
    mockList.mockResolvedValue({ pendingCount: 0, approvals: [] })
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/approvals', component: { template: '<div />' } }],
    })
    await router.push('/approvals?approval=4')
    const wrapper = mount(ApprovalsInbox, {
      global: { plugins: [router], stubs: { Icon: true } },
    })
    await flushPromises()
    expect(wrapper.get('[data-testid="approvals-tab-pending"]').classes()).toContain('btn-primary')
    expect(wrapper.get('[data-testid="approval-row-4"]').text()).toContain('Still waiting')
  })

  it('drops approve and reject when another session already decided it', async () => {
    const pending = {
      id: 4,
      tool: 'memory_add',
      preview: 'Still waiting',
      status: 'pending',
      expiresAt: 1,
      created: 1,
      requestedBy: { kind: 'chat' as const },
      sideEffect: 'write' as const,
      canAlwaysAllow: true,
    }
    const decided = {
      ...pending,
      status: 'executed',
      preview: 'Already decided',
      canAlwaysAllow: false,
    }
    let reads = 0
    mockGetOne.mockImplementation(async () => {
      reads += 1
      return reads === 1 ? pending : decided
    })
    mockList.mockImplementation(async (status: string) => {
      if (status === 'decided') return { pendingCount: 0, approvals: [decided] }
      return { pendingCount: 0, approvals: [] }
    })
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/approvals', component: { template: '<div />' } }],
    })
    await router.push('/approvals?approval=4')
    const wrapper = mount(ApprovalsInbox, {
      global: { plugins: [router], stubs: { Icon: true } },
    })
    await flushPromises()
    expect(wrapper.get('[data-testid="approvals-tab-decided"]').classes()).toContain('btn-primary')
    expect(wrapper.get('[data-testid="approval-row-4"]').text()).toContain('Already decided')
  })

  it('refreshes a pending card when the pending list reloads without it', async () => {
    const pending = {
      id: 4,
      tool: 'memory_add',
      preview: 'Still waiting',
      status: 'pending',
      expiresAt: 1,
      created: 1,
      requestedBy: { kind: 'chat' as const },
      sideEffect: 'write' as const,
      canAlwaysAllow: true,
    }
    const decided = {
      ...pending,
      status: 'executed',
      preview: 'Already decided',
      canAlwaysAllow: false,
    }
    mockGetOne.mockResolvedValue(pending)
    mockList.mockImplementation(async (status: string) => {
      if (status === 'pending') return { pendingCount: 1, approvals: [pending] }
      return { pendingCount: 0, approvals: [] }
    })
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/approvals', component: { template: '<div />' } }],
    })
    await router.push('/approvals?approval=4')
    const wrapper = mount(ApprovalsInbox, {
      global: { plugins: [router], stubs: { Icon: true } },
    })
    await flushPromises()
    expect(wrapper.get('[data-testid="approval-row-4"]').text()).toContain('Still waiting')
    mockGetOne.mockResolvedValue(decided)
    mockList.mockImplementation(async (status: string) => {
      if (status === 'decided') return { pendingCount: 0, approvals: [decided] }
      return { pendingCount: 0, approvals: [] }
    })
    await useApprovalsStore().load('pending')
    await flushPromises()
    expect(wrapper.get('[data-testid="approvals-tab-decided"]').classes()).toContain('btn-primary')
    expect(wrapper.get('[data-testid="approval-row-4"]').text()).toContain('Already decided')
  })

  it('replaces an approved card when a later decided refresh has finished', async () => {
    const approved = {
      id: 4,
      tool: 'memory_add',
      preview: 'Approved and waiting',
      status: 'approved',
      expiresAt: 1,
      created: 1,
      requestedBy: { kind: 'chat' as const },
      sideEffect: 'write' as const,
      canAlwaysAllow: false,
    }
    const executed = { ...approved, status: 'executed', preview: 'Now executed' }
    mockGetOne.mockResolvedValue(approved)
    mockList.mockImplementation(async () => ({ pendingCount: 0, approvals: [] }))
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/approvals', component: { template: '<div />' } }],
    })
    await router.push('/approvals?approval=4')
    const wrapper = mount(ApprovalsInbox, {
      global: { plugins: [router], stubs: { Icon: true } },
    })
    await flushPromises()
    expect(wrapper.get('[data-testid="approval-row-4"]').text()).toContain('Approved and waiting')
    mockGetOne.mockResolvedValue(executed)
    await wrapper.get('[data-testid="approvals-tab-decided"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-testid="approval-row-4"]').text()).toContain('Now executed')
    expect(wrapper.get('[data-testid="approval-row-4"]').text()).not.toContain(
      'Approved and waiting'
    )
  })

  it('still lists pending approvals when the linked approval cannot be loaded', async () => {
    const pending = {
      id: 4,
      tool: 'memory_add',
      preview: 'Still waiting',
      status: 'pending',
      expiresAt: 1,
      created: 1,
      requestedBy: { kind: 'chat' as const },
      sideEffect: 'write' as const,
      canAlwaysAllow: false,
    }
    mockList.mockImplementation(async (status: string) => {
      if (status === 'pending') return { pendingCount: 1, approvals: [pending] }
      return { pendingCount: 1, approvals: [] }
    })
    mockGetOne.mockRejectedValue(new Error('missing'))
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/approvals', component: { template: '<div />' } }],
    })
    await router.push('/approvals?approval=999')
    const wrapper = mount(ApprovalsInbox, {
      global: { plugins: [router], stubs: { Icon: true } },
    })
    await flushPromises()
    expect(wrapper.get('[data-testid="approval-row-4"]').text()).toContain('Still waiting')
    expect(wrapper.find('[data-testid="approvals-empty"]').exists()).toBe(false)
  })
})
