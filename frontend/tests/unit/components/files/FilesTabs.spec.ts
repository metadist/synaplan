import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import FilesTabs from '@/components/files/FilesTabs.vue'
import { loadAllMessages } from '@/i18n/loadAllMessages'

const en = loadAllMessages('en')

const runtimeFeatures = { computeWorkspacesEnabled: false }

vi.mock('@/services/api/httpClient', () => ({
  getConfigSync: () => ({ features: runtimeFeatures }),
}))

vi.mock('@/services/filesService', () => ({
  default: { getFacets: vi.fn().mockResolvedValue({ incoming: 0 }) },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ isAdmin: false }),
}))

function mountTabs() {
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    messages: { en },
  })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/files', component: { template: '<div />' } },
      { path: '/files/workspace', component: { template: '<div />' } },
    ],
  })
  return mount(FilesTabs, {
    props: { active: 'files' },
    global: {
      plugins: [i18n, router],
      stubs: { Icon: { template: '<i />' } },
    },
  })
}

describe('FilesTabs', () => {
  beforeEach(() => {
    runtimeFeatures.computeWorkspacesEnabled = false
  })

  it('hides the Workspace tab when the folder flag is off', () => {
    runtimeFeatures.computeWorkspacesEnabled = false
    const wrapper = mountTabs()
    expect(wrapper.find('[data-testid="tab-files-workspace"]').exists()).toBe(false)
  })

  it('shows the Workspace tab when the folder flag is on', () => {
    runtimeFeatures.computeWorkspacesEnabled = true
    const wrapper = mountTabs()
    expect(wrapper.get('[data-testid="tab-files-workspace"]').text()).toContain('Workspace')
  })
})
