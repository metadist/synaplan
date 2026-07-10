import { setActivePinia, createPinia } from 'pinia'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ isAuthenticated: true }) }))
vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({ usageTaximeter: { enabled: true } }),
}))
vi.mock('@/api/usageApi', () => ({ getUsageSummary: vi.fn() }))

import ConsumptionRing from '@/components/usage/ConsumptionRing.vue'
import { useUsageTaximeterStore, type MessageUsage } from '@/stores/usageTaximeter'

function usage(modelKey: string, cost: string, tokens = 500): MessageUsage {
  return {
    modelKey,
    cost,
    kind: 'LLM',
    promptTokens: 100,
    completionTokens: tokens - 100,
    totalTokens: tokens,
  }
}

const stubs = { RouterLink: true, Icon: true }

function mountRing() {
  return mount(ConsumptionRing, { global: { stubs } })
}

describe('ConsumptionRing', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('renders the euro value in the centre', () => {
    const store = useUsageTaximeterStore()
    store.applyComplete(usage('openai:gpt-4o', '0.02'), { todayCost: '1.02', todayTokens: 10 })

    const wrapper = mountRing()
    expect(wrapper.find('.usage-ring__value').text()).toContain('1.02')
  })

  it('renders one arc per epoch segment (plus the track circle)', () => {
    const store = useUsageTaximeterStore()
    // Two model epochs → base + 2 epochs = 3 segments after a pre-session base.
    store.applyComplete(usage('openai:gpt-4o', '0.02'), { todayCost: '1.02', todayTokens: 10 })
    store.applyComplete(usage('anthropic:claude', '0.03'), { todayCost: '1.05', todayTokens: 20 })

    const wrapper = mountRing()
    const circles = wrapper.findAll('circle')
    // 1 track + N segment arcs
    expect(circles.length).toBe(1 + store.epochSegments.length)
  })

  it('opens the stats panel when tapped', async () => {
    const store = useUsageTaximeterStore()
    store.applyComplete(usage('openai:gpt-4o', '0.02'), { todayCost: '1.02', todayTokens: 10 })
    const wrapper = mountRing()

    await wrapper.find('.usage-ring__trigger').trigger('click')
    expect(wrapper.find('[role="dialog"]').exists()).toBe(true)
  })
})
