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

function mountPage(isAdmin: boolean, client = 'nextcloud') {
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
    props: { client },
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

  function link(overrides: Record<string, unknown> = {}) {
    return {
      id: 5,
      client: 'nextcloud',
      host: 'files.example.org',
      external_id: 'ada',
      key_id: 9,
      key_label: 'Nextcloud',
      created: 1,
      last_seen: 2,
      can_copy: true,
      can_read: true,
      read_scope: { mode: 'allowed', folders: ['Invoices/2026', 'Specs'] },
      ...overrides,
    }
  }

  it('says what Synaplan may read in a cloud that hands out files', async () => {
    listMine.mockResolvedValue([link()])
    const wrapper = mountPage(false)
    await flushPromises()

    expect(wrapper.get('[data-testid="text-read-scope"]').text()).toBe(
      'Synaplan may read: the folders you watch, Invoices/2026, Specs.'
    )
  })

  it('names all files and only watched folders as the cloud reports them', async () => {
    listMine.mockResolvedValue([
      link({ id: 6, read_scope: { mode: 'all', folders: [] } }),
      link({ id: 7, read_scope: { mode: 'watched', folders: ['Ignored'] } }),
    ])
    const wrapper = mountPage(false)
    await flushPromises()

    expect(wrapper.findAll('[data-testid="text-read-scope"]').map((line) => line.text())).toEqual([
      'Synaplan may read: all your files.',
      'Synaplan may read: the folders you watch.',
    ])
  })

  it('says nothing about reading for a cloud whose app cannot hand out files', async () => {
    listMine.mockResolvedValue([
      link({ can_read: false }),
      link({ id: 8, can_read: undefined, read_scope: undefined }),
    ])
    const wrapper = mountPage(false)
    await flushPromises()

    expect(wrapper.find('[data-testid="text-read-scope"]').exists()).toBe(false)
  })
})
