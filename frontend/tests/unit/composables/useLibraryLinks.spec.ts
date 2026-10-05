import { defineComponent } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore, type User } from '@/stores/auth'
import { useLibraryLinks } from '@/composables/useLibraryLinks'

const runtimeFeatures: { computeWorkspacesEnabled?: boolean } = {}

vi.mock('@/services/api/httpClient', () => ({
  getConfigSync: () => ({ features: runtimeFeatures }),
}))

vi.mock('@/services/filesService', () => ({
  default: { getFacets: vi.fn().mockResolvedValue({ incoming: 0 }) },
}))

function mountLinks(user: { level: string; isAdmin?: boolean } | null) {
  setActivePinia(createPinia())
  const auth = useAuthStore()
  auth.user = user
    ? ({
        id: 1,
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
      '<ul><li v-for="link in links" :key="link.id" :data-testid="link.sidebarTestId">{{ link.label }}</li></ul>',
  })

  return mount(Harness)
}

describe('useLibraryLinks', () => {
  beforeEach(() => {
    runtimeFeatures.computeWorkspacesEnabled = false
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
