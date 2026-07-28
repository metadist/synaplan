import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import SortingPromptConfiguration from '@/components/config/SortingPromptConfiguration.vue'

const { mockGetConfigValues, mockUpdateConfigValue } = vi.hoisted(() => ({
  mockGetConfigValues: vi.fn(),
  mockUpdateConfigValue: vi.fn(),
}))

vi.mock('@/services/api/adminConfigApi', () => ({
  getConfigValues: mockGetConfigValues,
  updateConfigValue: mockUpdateConfigValue,
}))

vi.mock('@/services/api/promptsApi', () => ({
  promptsApi: {
    getSortingPrompt: vi.fn().mockResolvedValue({
      id: 1,
      topic: 'tools:sort',
      shortDescription: 'Sort messages',
      prompt: '# Sort',
      renderedPrompt: '# Sort',
      categories: [],
    }),
    getPlanningPrompt: vi.fn().mockResolvedValue({
      id: 2,
      topic: 'tools:plan',
      shortDescription: 'Plan tasks',
      prompt: '# Plan',
      renderedPrompt: '# Plan',
    }),
    updateSortingPrompt: vi.fn(),
    updatePlanningPrompt: vi.fn(),
  },
}))

vi.mock('@/services/api/configApi', () => ({
  getModels: vi.fn().mockResolvedValue({ success: true, models: { CHAT: [] } }),
  getPlannerModel: vi.fn().mockResolvedValue({
    success: true,
    modelId: null,
    fallbackModelId: null,
  }),
  savePlannerModel: vi.fn(),
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ isAdmin: true }),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({
    success: vi.fn(),
    error: vi.fn(),
    warning: vi.fn(),
  }),
}))

const mountComponent = () =>
  mount(SortingPromptConfiguration, {
    global: {
      stubs: {
        Icon: true,
        RouterLink: { template: '<a><slot /></a>' },
      },
    },
  })

describe('SortingPromptConfiguration routing override', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockUpdateConfigValue.mockResolvedValue({ success: true, requiresRestart: false })
  })

  it('warns when the personal routing value differs from the workspace default', async () => {
    mockGetConfigValues.mockResolvedValue({
      MULTITASK_ROUTING_ENABLED: {
        value: 'true',
        isSet: true,
        isMasked: false,
        hasPersonalOverride: true,
        effectiveForMe: 'false',
      },
    })

    const wrapper = mountComponent()
    await flushPromises()

    expect(wrapper.find('[data-testid="multitask-override-warning"]').exists()).toBe(true)
    expect(
      (wrapper.find('[data-testid="toggle-multitask-enabled"] input').element as HTMLInputElement)
        .checked
    ).toBe(true)
  })

  it('clears the personal override by reapplying the workspace default', async () => {
    mockGetConfigValues.mockResolvedValue({
      MULTITASK_ROUTING_ENABLED: {
        value: 'true',
        isSet: true,
        isMasked: false,
        hasPersonalOverride: true,
        effectiveForMe: 'false',
      },
    })

    const wrapper = mountComponent()
    await flushPromises()
    await wrapper.find('[data-testid="btn-clear-multitask-override"]').trigger('click')
    await flushPromises()

    expect(mockUpdateConfigValue).toHaveBeenCalledWith('MULTITASK_ROUTING_ENABLED', 'true')
    expect(wrapper.find('[data-testid="multitask-override-warning"]').exists()).toBe(false)
  })

  it('does not warn when the acting admin inherits the workspace default', async () => {
    mockGetConfigValues.mockResolvedValue({
      MULTITASK_ROUTING_ENABLED: {
        value: 'true',
        isSet: true,
        isMasked: false,
        hasPersonalOverride: false,
        effectiveForMe: 'true',
      },
    })

    const wrapper = mountComponent()
    await flushPromises()

    expect(wrapper.find('[data-testid="multitask-override-warning"]').exists()).toBe(false)
  })
})
