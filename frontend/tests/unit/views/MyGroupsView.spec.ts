import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useAuthStore, type User } from '@/stores/auth'

const listMyGroups = vi.fn()

vi.mock('@/services/api/iamApi', () => ({
  iamApi: {
    listMyGroups: (...args: unknown[]) => listMyGroups(...args),
  },
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: vi.fn(), error: vi.fn() }),
}))

vi.mock('@iconify/vue', () => ({
  Icon: { template: '<i />' },
}))

import MyGroupsView from '@/views/MyGroupsView.vue'

function mountView(isAdmin = false) {
  setActivePinia(createPinia())
  const auth = useAuthStore()
  auth.user = {
    id: 2,
    email: 'demo@synaplan.com',
    level: isAdmin ? 'ADMIN' : 'PRO',
    isAdmin,
  } as User
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/admin/people', component: { template: '<div />' } },
    ],
  })
  return mount(MyGroupsView, {
    global: {
      plugins: [router],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        PageHeader: { template: '<div><slot /></div>' },
      },
    },
  })
}

describe('MyGroupsView', () => {
  beforeEach(() => {
    listMyGroups.mockReset()
  })

  it('lists groups the current user belongs to', async () => {
    listMyGroups.mockResolvedValue([
      {
        id: 4,
        name: 'Sales',
        slug: 'sales',
        description: '',
        kind: 'manual',
        memberCount: 3,
        role: 'member',
      },
    ])
    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.get('[data-testid="card-my-group-4"]').text()).toContain('Sales')
    expect(wrapper.get('[data-testid="card-my-group-4"]').text()).toContain('Member')
    expect(wrapper.find('[data-testid="link-my-groups-people"]').exists()).toBe(false)
  })

  it('shows an empty state and a People link for admins', async () => {
    listMyGroups.mockResolvedValue([])
    const wrapper = mountView(true)
    await flushPromises()

    expect(wrapper.get('[data-testid="my-groups-empty"]').text()).toContain('People')
    expect(wrapper.get('[data-testid="link-my-groups-people"]').attributes('href')).toBe(
      '/admin/people'
    )
  })
})
