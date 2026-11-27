import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'

const plugins: { name: string; displayName?: string; description?: string }[] = []

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({
    get plugins() {
      return plugins
    },
    apiBaseUrl: '',
    init: vi.fn().mockResolvedValue(undefined),
  }),
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ user: { id: 1 } }),
}))

vi.mock('@/services/api/authedFetch', () => ({
  authedFetch: vi.fn(),
}))

import PluginView from '@/views/PluginView.vue'

async function mountView(pluginName: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/plugins/:pluginName', component: PluginView }],
  })
  await router.push(`/plugins/${pluginName}`)
  await router.isReady()
  const wrapper = mount(PluginView, {
    global: {
      plugins: [router],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        PageHeader: { props: ['title', 'subtitle'], template: '<h1>{{ title }}</h1>' },
      },
    },
  })
  await flushPromises()
  return wrapper
}

describe('PluginView', () => {
  beforeEach(() => {
    plugins.length = 0
    vi.spyOn(console, 'error').mockImplementation(() => {})
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('titles the page with the name the plugin declares', async () => {
    plugins.push({ name: 'synascriber', displayName: 'Meeting notes' })

    const wrapper = await mountView('synascriber')

    expect(wrapper.get('[data-testid="section-header"]').text()).toBe('Meeting notes')
  })

  it('says in one sentence that the page could not be loaded', async () => {
    plugins.push({ name: 'broken' })

    const wrapper = await mountView('broken')

    await vi.waitFor(() =>
      expect(wrapper.text()).toContain('This plugin could not be loaded. Reload the page.')
    )
    expect(wrapper.text()).not.toContain('Failed to load plugin')
  })

  it('says a plugin the person does not have is not found', async () => {
    const wrapper = await mountView('missing')

    expect(wrapper.get('[data-testid="section-header"]').text()).toBe('Plugin')
    expect(wrapper.text()).toContain('Plugin not found or not enabled for your account')
  })
})
