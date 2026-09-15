import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useAuthStore, type User } from '@/stores/auth'

const listMine = vi.fn()

vi.mock('@/services/api/platformLinksApi', () => ({
  platformLinksApi: {
    listMine: (...args: unknown[]) => listMine(...args),
    disconnect: vi.fn(),
  },
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: vi.fn(), error: vi.fn() }),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ confirm: vi.fn() }),
}))

import LinkedPlatformsConfiguration from '@/components/config/LinkedPlatformsConfiguration.vue'

function mountPage(isAdmin: boolean) {
  setActivePinia(createPinia())
  const auth = useAuthStore()
  auth.user = {
    id: 1,
    email: isAdmin ? 'admin@test.com' : 'user@test.com',
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
  return mount(LinkedPlatformsConfiguration, {
    global: {
      plugins: [router],
      stubs: {
        PageHeader: { template: '<div />' },
      },
    },
  })
}

describe('LinkedPlatformsConfiguration', () => {
  beforeEach(() => {
    listMine.mockReset()
    listMine.mockResolvedValue([])
  })

  it('shows the Platform instances pointer for admins', async () => {
    const wrapper = mountPage(true)
    await flushPromises()

    expect(wrapper.find('[data-testid="text-admin-platform-instances-pointer"]').exists()).toBe(
      true
    )
    expect(wrapper.find('[data-testid="link-admin-platform-instances"]').attributes('href')).toBe(
      '/admin/people?tab=linked-platforms'
    )
  })

  it('hides the Platform instances pointer for non-admins', async () => {
    const wrapper = mountPage(false)
    await flushPromises()

    expect(wrapper.find('[data-testid="text-admin-platform-instances-pointer"]').exists()).toBe(
      false
    )
  })
})
