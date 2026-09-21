import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useAuthStore, type User } from '@/stores/auth'

const listMyGroups = vi.fn()
const leaveGroup = vi.fn()
const countMyGrantsToGroup = vi.fn()
const chooseLeave = vi.fn()

vi.mock('@/services/api/iamApi', () => ({
  iamApi: {
    listMyGroups: (...args: unknown[]) => listMyGroups(...args),
    leaveGroup: (...args: unknown[]) => leaveGroup(...args),
    countMyGrantsToGroup: (...args: unknown[]) => countMyGrantsToGroup(...args),
  },
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: vi.fn(), error: vi.fn() }),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ choose: (...args: unknown[]) => chooseLeave(...args) }),
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
    leaveGroup.mockReset()
    countMyGrantsToGroup.mockReset()
    chooseLeave.mockReset()
    countMyGrantsToGroup.mockResolvedValue(0)
    chooseLeave.mockResolvedValue('confirm')
    leaveGroup.mockResolvedValue(undefined)
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
    expect(wrapper.get('[data-testid="btn-leave-group-4"]').text()).toContain('Leave')
  })

  it('lets a member leave a group they were added to', async () => {
    listMyGroups.mockResolvedValue([
      {
        id: 4,
        name: 'Sales',
        slug: 'sales',
        description: '',
        kind: 'manual',
        memberCount: 3,
        role: 'member',
        canLeave: true,
        membershipSource: 'manual',
      },
    ])
    const wrapper = mountView()
    await flushPromises()

    await wrapper.get('[data-testid="btn-leave-group-4"]').trigger('click')
    await flushPromises()

    expect(chooseLeave).toHaveBeenCalled()
    const message = String(
      (chooseLeave.mock.calls[0]?.[0] as { message?: string } | undefined)?.message ?? ''
    )
    expect(message).toContain('stays shared')
    expect(
      (chooseLeave.mock.calls[0]?.[0] as { extraText?: string } | undefined)?.extraText
    ).toBeUndefined()
    expect(leaveGroup).toHaveBeenCalledWith(4, false)
    expect(wrapper.find('[data-testid="card-my-group-4"]').exists()).toBe(false)
  })

  it('offers leave and stop sharing only when this user shared something', async () => {
    listMyGroups.mockResolvedValue([
      {
        id: 4,
        name: 'Sales',
        slug: 'sales',
        description: '',
        kind: 'manual',
        memberCount: 3,
        role: 'member',
        canLeave: true,
        membershipSource: 'manual',
      },
    ])
    countMyGrantsToGroup.mockResolvedValue(2)
    chooseLeave.mockResolvedValue('extra')
    const wrapper = mountView()
    await flushPromises()

    await wrapper.get('[data-testid="btn-leave-group-4"]').trigger('click')
    await flushPromises()

    const options = chooseLeave.mock.calls[0]?.[0] as { message?: string; extraText?: string }
    expect(options.message).toContain('2 items')
    expect(options.message).toContain('stay shared')
    expect(options.extraText).toContain('stop sharing')
    expect(leaveGroup).toHaveBeenCalledWith(4, true)
  })

  it('hides Leave for a login-synced membership', async () => {
    listMyGroups.mockResolvedValue([
      {
        id: 8,
        name: 'Everyone',
        slug: 'everyone',
        description: '',
        kind: 'directory',
        memberCount: 12,
        role: 'member',
        canLeave: false,
        membershipSource: 'directory',
      },
    ])
    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.find('[data-testid="btn-leave-group-8"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="hint-leave-directory-8"]').text()).toContain('login')
  })

  it('still shows Leave when a directory group has a manual membership', async () => {
    listMyGroups.mockResolvedValue([
      {
        id: 9,
        name: 'IT',
        slug: 'it',
        description: '',
        kind: 'directory',
        memberCount: 4,
        role: 'member',
        canLeave: true,
        membershipSource: 'manual',
      },
    ])
    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.find('[data-testid="btn-leave-group-9"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="hint-leave-directory-9"]').exists()).toBe(false)
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
