import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import PlannerModelCard from '@/components/config/PlannerModelCard.vue'
import type { AIModel } from '@/types/ai-models'

const { getModels, getPlannerModel, savePlannerModel } = vi.hoisted(() => ({
  getModels: vi.fn(),
  getPlannerModel: vi.fn(),
  savePlannerModel: vi.fn(),
}))

const notify = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }))

vi.mock('@/services/api/configApi', () => ({
  getModels,
  getPlannerModel,
  savePlannerModel,
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => notify,
}))

const chatModels: AIModel[] = [
  {
    id: 12,
    service: 'groq',
    name: 'GPT OSS 120B',
    tag: 'chat',
    providerId: 'openai/gpt-oss-120b',
    quality: 1,
    rating: 1,
    priceIn: 0,
    priceOut: 0,
    description: null,
    isSystemModel: false,
    features: [],
  },
  {
    id: 53,
    service: 'anthropic',
    name: 'Claude Sonnet',
    tag: 'chat',
    providerId: 'claude-sonnet-5',
    quality: 1,
    rating: 1,
    priceIn: 0,
    priceOut: 0,
    description: null,
    isSystemModel: false,
    features: [],
  },
]

describe('PlannerModelCard', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    getModels.mockResolvedValue({ success: true, models: { CHAT: chatModels }, providers: [] })
    savePlannerModel.mockResolvedValue({
      success: true,
      modelId: 12,
      readingModelId: 12,
      checkingModelId: 53,
    })
  })

  it('starts collapsed, expands on click, and shows all 3 slots when research is enabled', async () => {
    getPlannerModel.mockResolvedValue({
      success: true,
      modelId: null,
      fallbackModelId: 12,
      researchEnabled: true,
      readingModelId: null,
      checkingModelId: null,
    })

    const wrapper = mount(PlannerModelCard)
    await flushPromises()

    expect(wrapper.get('[data-testid="badge-agent-models-summary"]').text()).toBe('All automatic')
    expect(wrapper.get('[data-testid="btn-toggle-agent-models"]').attributes('aria-expanded')).toBe(
      'false'
    )

    await wrapper.get('[data-testid="btn-toggle-agent-models"]').trigger('click')
    expect(wrapper.get('[data-testid="btn-toggle-agent-models"]').attributes('aria-expanded')).toBe(
      'true'
    )
    expect(wrapper.find('[data-testid="select-planner-model"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="select-research-reading-model"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="select-research-checking-model"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="text-research-lead-note"]').text()).toContain(
      'always use the model selected in your chat'
    )

    await wrapper.get('[data-testid="select-research-reading-model"]').setValue('12')
    await flushPromises()

    expect(savePlannerModel).toHaveBeenCalledWith({ readingModelId: 12 })
    expect(wrapper.get('[data-testid="badge-agent-models-summary"]').text()).toBe('1 custom')
  })

  it('hides the research slots and lead note when research is turned off (U11)', async () => {
    getPlannerModel.mockResolvedValue({
      success: true,
      modelId: 53,
      fallbackModelId: 12,
      researchEnabled: false,
      readingModelId: null,
      checkingModelId: null,
    })

    const wrapper = mount(PlannerModelCard)
    await flushPromises()

    expect(wrapper.get('[data-testid="badge-agent-models-summary"]').text()).toBe('1 custom')
    expect(wrapper.find('[data-testid="slot-research-reading"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="slot-research-checking"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="text-research-lead-note"]').exists()).toBe(false)
  })
})
