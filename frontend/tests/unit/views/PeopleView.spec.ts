import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

const listAdminGroups = vi.fn()
const mockGetUsers = vi.fn()
const listAudit = vi.fn()
const getConfigSync = vi.fn()

vi.mock('@/services/api/httpClient', () => ({
  getConfigSync: () => getConfigSync(),
}))

vi.mock('@/services/api/iamApi', () => ({
  iamApi: {
    listAdminGroups: (...args: unknown[]) => listAdminGroups(...args),
    createGroup: vi.fn(),
    updateGroup: vi.fn(),
    deleteGroup: vi.fn(),
    listMembers: vi.fn().mockResolvedValue([]),
    setMember: vi.fn(),
    removeMember: vi.fn(),
    listMyGroups: vi.fn(),
    listAudit: (...args: unknown[]) => listAudit(...args),
    getGroupConfig: vi.fn().mockResolvedValue({ settings: {}, conflicts: {} }),
    listLocks: vi.fn().mockResolvedValue({}),
    putGroupConfig: vi.fn(),
    patchLocks: vi.fn(),
  },
}))

vi.mock('@/services/api/platformLinksApi', () => ({
  platformLinksApi: {
    listAdminInstances: vi.fn().mockResolvedValue([]),
    approveInstance: vi.fn(),
    revokeInstance: vi.fn(),
  },
}))

vi.mock('@/services/api/adminApi', () => ({
  adminApi: {
    getUsers: (...args: unknown[]) => mockGetUsers(...args),
    updateUserLevel: vi.fn(),
    deleteUser: vi.fn(),
  },
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: vi.fn(), error: vi.fn() }),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ confirm: vi.fn(), prompt: vi.fn() }),
}))

vi.mock('@iconify/vue', () => ({
  Icon: { template: '<i />' },
}))

import PeopleView from '@/views/PeopleView.vue'

function mountView() {
  setActivePinia(createPinia())
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/', component: { template: '<div />' } }],
  })
  return mount(PeopleView, {
    global: {
      plugins: [router],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        PageHeader: { template: '<div><slot /></div>' },
        Teleport: true,
      },
    },
  })
}

describe('PeopleView', () => {
  beforeEach(() => {
    getConfigSync.mockReset()
    getConfigSync.mockReturnValue({ features: {} })
    listAdminGroups.mockReset()
    mockGetUsers.mockReset()
    mockGetUsers.mockResolvedValue({ users: [], total: 0, page: 1, limit: 50 })
    listAudit.mockReset()
    listAudit.mockResolvedValue({
      entries: [
        {
          id: 1,
          actorId: 2,
          action: 'share.grant',
          kind: 'conversation',
          resourceId: '9',
          subject: { permission: 'use' },
          ip: '127.0.0.1',
          created: 1_700_000_000,
        },
      ],
      nextCursor: null,
    })
    listAdminGroups.mockResolvedValue([
      {
        id: 1,
        name: 'Sales',
        slug: 'sales',
        description: '',
        kind: 'manual',
        memberCount: 3,
        created: 1,
        updated: 1,
      },
    ])
  })

  it('hides Groups and Audit tabs when IAM groups are off', async () => {
    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.find('[data-testid="tab-users"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="tab-groups"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="tab-audit"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="tab-policies"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="tab-linked-platforms"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="section-users"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="section-audit"]').exists()).toBe(false)
  })

  it('renders Users, Groups and Audit tabs when IAM groups are on', async () => {
    getConfigSync.mockReturnValue({ features: { iamGroups: true } })
    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.find('[data-testid="tab-users"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="tab-groups"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="tab-audit"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="tab-policies"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="tab-linked-platforms"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="section-users"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="section-audit"]').exists()).toBe(false)
  })

  it('shows the Linked platforms tab when features.platformLinksEnabled is on', async () => {
    getConfigSync.mockReturnValue({ features: { platformLinksEnabled: true } })
    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.find('[data-testid="tab-linked-platforms"]').exists()).toBe(true)
  })

  it('shows the Policies tab when features.iamPolicies is on', async () => {
    getConfigSync.mockReturnValue({ features: { iamPolicies: true } })
    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.find('[data-testid="tab-policies"]').exists()).toBe(true)
  })

  it('lists audit events on the Audit tab', async () => {
    getConfigSync.mockReturnValue({ features: { iamGroups: true } })
    const wrapper = mountView()
    await flushPromises()

    await wrapper.get('[data-testid="tab-audit"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="section-audit"]').exists()).toBe(true)
    expect(listAudit).toHaveBeenCalled()
    expect(wrapper.text()).toContain('Shared')
  })

  it('lists groups on the Groups tab', async () => {
    getConfigSync.mockReturnValue({ features: { iamGroups: true } })
    const wrapper = mountView()
    await flushPromises()

    await wrapper.get('[data-testid="tab-groups"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="section-groups"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Sales')
    expect(listAdminGroups).toHaveBeenCalled()
  })
})
