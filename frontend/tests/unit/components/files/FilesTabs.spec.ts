import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import FilesTabs from '@/components/files/FilesTabs.vue'

const { route, links } = vi.hoisted(() => ({
  route: { path: '/files' },
  links: {
    value: [
      { id: 'browse', to: '/files', label: 'Browse' },
      { id: 'incoming', to: '/files/incoming', label: 'Incoming', badge: 3 },
      { id: 'generated', to: '/files/generated', label: 'Generated' },
      { id: 'search', to: '/files/search', label: 'Search' },
    ],
  },
}))

vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => route,
}))

vi.mock('@/composables/useLibraryLinks', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/composables/useLibraryLinks')>()),
  useLibraryLinks: () => ({ links }),
}))

const TabNavStub = {
  props: ['modelValue', 'tabs'],
  template:
    '<nav><a v-for="tab in tabs" :key="tab.id" :href="tab.to" :data-testid="tab.testid" :data-active="tab.id === modelValue" :data-badge="tab.badge">{{ tab.label }}</a></nav>',
}

const mountTabs = () =>
  mount(FilesTabs, {
    global: {
      stubs: {
        TabNav: TabNavStub,
        PageHeader: {
          props: ['title', 'subtitle'],
          template: '<header><p data-testid="subtitle">{{ subtitle }}</p><slot /></header>',
        },
      },
    },
  })

describe('FilesTabs', () => {
  beforeEach(() => {
    route.path = '/files'
  })

  it('renders every Library destination as a tab with the inbox badge', () => {
    const wrapper = mountTabs()
    expect(wrapper.findAll('a').map((a) => a.attributes('href'))).toEqual([
      '/files',
      '/files/incoming',
      '/files/generated',
      '/files/search',
    ])
    expect(wrapper.get('[data-testid="tab-files-incoming"]').attributes('data-badge')).toBe('3')
  })

  it('marks the current tab and explains it in the subtitle', () => {
    route.path = '/files/generated'
    const wrapper = mountTabs()
    expect(wrapper.get('[data-testid="tab-files-generated"]').attributes('data-active')).toBe(
      'true'
    )
    expect(wrapper.get('[data-testid="tab-files-browse"]').attributes('data-active')).toBe('false')
    expect(wrapper.get('[data-testid="subtitle"]').text()).toContain('Finished results')
  })
})
