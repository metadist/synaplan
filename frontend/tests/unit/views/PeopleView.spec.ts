import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

const listAdminGroups = vi.fn()
const mockGetUsers = vi.fn()

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
    listAdminGroups.mockReset()
    mockGetUsers.mockReset()
    mockGetUsers.mockResolvedValue({ users: [], total: 0, page: 1, limit: 50 })
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

  it('renders Users and Groups tabs', async () => {
    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.find('[data-testid="tab-users"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="tab-groups"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="section-users"]').exists()).toBe(true)
  })

  it('lists groups on the Groups tab', async () => {
    const wrapper = mountView()
    await flushPromises()

    await wrapper.get('[data-testid="tab-groups"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="section-groups"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Sales')
    expect(listAdminGroups).toHaveBeenCalled()
  })
})
