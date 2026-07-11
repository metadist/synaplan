import { setActivePinia, createPinia } from 'pinia'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ isAuthenticated: true }) }))
vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({ usageTaximeter: { enabled: true } }),
}))
vi.mock('@/api/usageApi', () => ({ getUsageSummary: vi.fn() }))

import ConsumptionBar from '@/components/usage/ConsumptionBar.vue'
import { useUsageTaximeterStore, type MessageUsage } from '@/stores/usageTaximeter'

function usage(modelKey: string, cost: string, tokens = 8420): MessageUsage {
  return {
    modelKey,
    cost,
    kind: 'LLM',
    promptTokens: 1000,
    completionTokens: tokens - 1000,
    totalTokens: tokens,
  }
}

const stubs = { RouterLink: true, Icon: true }

function mountBar() {
  return mount(ConsumptionBar, { global: { stubs } })
}

describe('ConsumptionBar', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('renders the head euro value and the "Tokens" foot label', () => {
    const store = useUsageTaximeterStore()
    store.applyComplete(usage('openai:gpt-4o', '0.02', 8420), {
      todayCost: '1.02',
      todayTokens: 8420,
    })

    const wrapper = mountBar()
    expect(wrapper.find('.usage-bar__head').text()).toContain('1.02')
    expect(wrapper.find('.usage-bar__foot').text()).toBe('Tokens')
  })

  it('renders one segment per epochSegments entry', () => {
    const store = useUsageTaximeterStore()
    store.applyComplete(usage('openai:gpt-4o', '0.02'), { todayCost: '1.02', todayTokens: 10 })

    const wrapper = mountBar()
    expect(wrapper.findAll('.usage-bar__segment')).toHaveLength(store.epochSegments.length)
  })

  it('shows the token tooltip on hover and hides it on leave', async () => {
    const store = useUsageTaximeterStore()
    store.applyComplete(usage('openai:gpt-4o', '0.02', 8420), {
      todayCost: '1.02',
      todayTokens: 8420,
    })
    const wrapper = mountBar()

    await wrapper.find('.usage-bar__trigger').trigger('mouseenter')
    expect(wrapper.find('[role="tooltip"]').exists()).toBe(true)
    expect(wrapper.find('[role="tooltip"]').text()).toContain('8,420')

    await wrapper.find('.usage-bar__trigger').trigger('mouseleave')
    expect(wrapper.find('[role="tooltip"]').exists()).toBe(false)
  })

  it('opens the stats panel on click and hides the tooltip', async () => {
    const store = useUsageTaximeterStore()
    store.applyComplete(usage('openai:gpt-4o', '0.02'), { todayCost: '1.02', todayTokens: 10 })
    const wrapper = mountBar()

    await wrapper.find('.usage-bar__trigger').trigger('mouseenter')
    await wrapper.find('.usage-bar__trigger').trigger('click')

    expect(wrapper.find('[role="dialog"]').exists()).toBe(true)
    expect(wrapper.find('[role="tooltip"]').exists()).toBe(false)
  })

  it('closes the panel on Escape', async () => {
    const store = useUsageTaximeterStore()
    store.applyComplete(usage('openai:gpt-4o', '0.02'), { todayCost: '1.02', todayTokens: 10 })
    const wrapper = mountBar()

    await wrapper.find('.usage-bar__trigger').trigger('click')
    expect(wrapper.find('[role="dialog"]').exists()).toBe(true)

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
  })

  it('lists used models with their session cost and the prompt count in the panel', async () => {
    const store = useUsageTaximeterStore()
    store.registerPrompt()
    store.applyComplete(usage('openai:gpt-4o', '0.02'), { todayCost: '0.02', todayTokens: 10 })
    const wrapper = mountBar()

    await wrapper.find('.usage-bar__trigger').trigger('click')
    const panelText = wrapper.find('[role="dialog"]').text()
    expect(panelText).toContain('gpt-4o')
    expect(panelText).toContain('Prompts in this session')
  })
})
