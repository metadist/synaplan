import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ToolsDropdown from '@/components/ToolsDropdown.vue'

const { features } = vi.hoisted(() => ({
  features: { selfAware: false, help: false, memoryService: false },
}))

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({ features }),
  default: { features, plugins: [] },
}))

vi.mock('@/services/api/nativeHaptics', () => ({
  triggerHapticImpact: vi.fn(),
}))

vi.mock('@/composables/useDesktopAgentFeature', () => ({
  isDesktopAgentEnabled: () => false,
}))

vi.mock('@/composables/useDesktopDevices', () => ({
  useDesktopDevices: () => ({
    activeDevices: { value: [] },
    hasActiveDevices: { value: false },
    ensureLoaded: vi.fn(),
  }),
}))

vi.mock('@/services/featuresService', () => ({
  getFeaturesStatus: vi.fn().mockResolvedValue({ features: {} }),
}))

function stubMatchMedia() {
  vi.stubGlobal(
    'matchMedia',
    vi.fn().mockImplementation((query: string) => ({
      matches: false,
      media: query,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
    }))
  )
}

async function mountDropdown() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/', component: { template: '<div />' } }],
  })
  await router.push('/')
  await router.isReady()

  return mount(ToolsDropdown, {
    global: {
      plugins: [pinia, router],
      stubs: { Icon: true },
    },
  })
}

describe('ToolsDropdown /help', () => {
  beforeEach(() => {
    stubMatchMedia()
    features.selfAware = false
  })

  it('hides /help when features.selfAware is off', async () => {
    const wrapper = await mountDropdown()
    await wrapper.get('[data-testid="btn-tools-toggle"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="btn-tool-help"]').exists()).toBe(false)
  })

  it('shows /help when features.selfAware is on', async () => {
    features.selfAware = true
    const wrapper = await mountDropdown()
    await wrapper.get('[data-testid="btn-tools-toggle"]').trigger('click')
    await flushPromises()

    const help = wrapper.get('[data-testid="btn-tool-help"]')
    expect(help.text()).toContain('Help')
    expect(help.text()).toContain('Ask what this AI assistant can do here')
  })
})
