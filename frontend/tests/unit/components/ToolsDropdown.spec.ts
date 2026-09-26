import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ToolsDropdown from '@/components/ToolsDropdown.vue'

const { features } = vi.hoisted(() => ({
  features: { selfAware: false, help: false, memoryService: false },
}))

const desktopEnabled = { value: false }
const activeDevices = ref<Array<{ id: number; name: string; status: string; lastSeen: number }>>([])
const hasActiveDevices = ref(false)

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({ features }),
  default: { features, plugins: [] },
}))

vi.mock('@/services/api/nativeHaptics', () => ({
  triggerHapticImpact: vi.fn(),
}))

vi.mock('@/composables/useDesktopAgentFeature', () => ({
  isDesktopAgentEnabled: () => desktopEnabled.value,
}))

vi.mock('@/composables/useDesktopDevices', () => ({
  useDesktopDevices: () => ({
    activeDevices,
    hasActiveDevices,
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

describe('ToolsDropdown summarize', () => {
  beforeEach(() => {
    stubMatchMedia()
    features.selfAware = false
    desktopEnabled.value = false
    activeDevices.value = []
    hasActiveDevices.value = false
  })

  it('lists Summarize a document and emits summarizeDocument', async () => {
    const wrapper = await mountDropdown()
    await wrapper.get('[data-testid="btn-tools-toggle"]').trigger('click')
    await flushPromises()

    const row = wrapper.get('[data-testid="btn-tool-summarize"]')
    expect(row.text()).toContain('Summarize a document')
    await row.trigger('click')
    expect(wrapper.emitted('summarizeDocument')).toHaveLength(1)
  })
})

describe('ToolsDropdown /help', () => {
  beforeEach(() => {
    stubMatchMedia()
    features.selfAware = false
    desktopEnabled.value = false
    activeDevices.value = []
    hasActiveDevices.value = false
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

describe('ToolsDropdown run on this computer', () => {
  beforeEach(() => {
    stubMatchMedia()
    desktopEnabled.value = true
    hasActiveDevices.value = true
  })

  it('stops saying the computer is connected after the check-in window', async () => {
    vi.useFakeTimers()
    try {
      const now = Math.floor(Date.now() / 1000)
      activeDevices.value = [{ id: 1, name: 'tower', status: 'active', lastSeen: now - 100 }]
      const wrapper = await mountDropdown()
      await wrapper.get('[data-testid="btn-tools-toggle"]').trigger('click')
      await flushPromises()

      const row = wrapper.get('[data-testid="btn-tool-run-on-device"]')
      expect(row.text()).toContain('tower is connected')

      await vi.advanceTimersByTimeAsync(90_000)
      await flushPromises()
      expect(row.text()).toContain('tower is not connected')
      wrapper.unmount()
    } finally {
      vi.useRealTimers()
    }
  })
})
