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

const mountHeader = () =>
  mount(FilesTabs, {
    global: {
      stubs: {
        PageHeader: {
          props: ['title', 'subtitle'],
          template: '<header><p data-testid="subtitle">{{ subtitle }}</p></header>',
        },
      },
    },
  })

describe('FilesTabs', () => {
  beforeEach(() => {
    route.path = '/files'
  })

  it('explains the current Library section and does not repeat the sidebar links', () => {
    route.path = '/files/generated'
    const wrapper = mountHeader()
    expect(wrapper.get('[data-testid="subtitle"]').text()).toContain('Finished results')
    expect(wrapper.find('[data-testid="files-tabs"]').exists()).toBe(false)
  })
})
