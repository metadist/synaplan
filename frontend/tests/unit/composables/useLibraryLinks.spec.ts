import { defineComponent, nextTick } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore, type User } from '@/stores/auth'
import {
  refreshIncomingCount,
  resetIncomingCount,
  useLibraryLinks,
} from '@/composables/useLibraryLinks'

const runtimeFeatures: { computeWorkspacesEnabled?: boolean } = {}
const getFacets = vi.hoisted(() => vi.fn())

vi.mock('@/services/api/httpClient', () => ({
  getConfigSync: () => ({ features: runtimeFeatures }),
}))

vi.mock('@/services/filesService', () => ({
  default: { getFacets },
}))

const incomingBadge = (wrapper: ReturnType<typeof mountLinks>) =>
  wrapper.get('[data-testid="link-sidebar-v2-files-incoming"]').attributes('data-badge')

function mountLinks(user: { level: string; isAdmin?: boolean; id?: number } | null) {
  setActivePinia(createPinia())
  const auth = useAuthStore()
  auth.user = user
    ? ({
        id: user.id ?? 1,
        email: 'user@test.com',
        level: user.level,
        isAdmin: user.isAdmin === true,
      } as User)
    : null

  const Harness = defineComponent({
    setup() {
      return useLibraryLinks()
    },
    template:
      '<ul><li v-for="link in links" :key="link.id" :data-testid="link.sidebarTestId" :data-badge="link.badge ?? \'\'">{{ link.label }}</li></ul>',
  })

  return mount(Harness)
}

describe('useLibraryLinks', () => {
  beforeEach(() => {
    runtimeFeatures.computeWorkspacesEnabled = false
    resetIncomingCount()
    getFacets.mockReset()
    getFacets.mockResolvedValue({ incoming: 0 })
  })

  it('loads the inbox badge for the next user after a reset', async () => {
    getFacets.mockResolvedValueOnce({ incoming: 4 })
    const first = mountLinks({ level: 'PRO', id: 1 })
    await flushPromises()
    expect(incomingBadge(first)).toBe('4')

    resetIncomingCount()
    getFacets.mockResolvedValueOnce({ incoming: 1 })
    const auth = useAuthStore()
    auth.user = { ...(auth.user as User), id: 2 }
    await nextTick()
    await flushPromises()

    expect(getFacets).toHaveBeenCalledTimes(2)
    expect(incomingBadge(first)).toBe('1')
  })

  it('updates the badge when the inbox changes', async () => {
    getFacets.mockResolvedValueOnce({ incoming: 2 })
    const wrapper = mountLinks({ level: 'PRO' })
    await flushPromises()
    expect(incomingBadge(wrapper)).toBe('2')

    getFacets.mockResolvedValueOnce({ incoming: 0 })
    await refreshIncomingCount()
    await nextTick()

    expect(incomingBadge(wrapper)).toBe('')
  })

  it('hides Workspace when the folder flag is off', () => {
    runtimeFeatures.computeWorkspacesEnabled = false
    const wrapper = mountLinks(null)
    expect(wrapper.find('[data-testid="link-sidebar-v2-files-workspace"]').exists()).toBe(false)
  })

  it('shows Workspace when the folder flag is on', () => {
    runtimeFeatures.computeWorkspacesEnabled = true
    const wrapper = mountLinks(null)
    expect(wrapper.get('[data-testid="link-sidebar-v2-files-workspace"]').text()).toContain(
      'Workspace'
    )
  })

  it('shows Vectors only for admins', () => {
    const member = mountLinks({ level: 'PRO' })
    expect(member.find('[data-testid="link-sidebar-v2-files-vectors"]').exists()).toBe(false)

    const admin = mountLinks({ level: 'ADMIN', isAdmin: true })
    expect(admin.find('[data-testid="link-sidebar-v2-files-vectors"]').exists()).toBe(true)
  })
})
