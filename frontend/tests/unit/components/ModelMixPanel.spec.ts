import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ModelMixPanel from '@/components/chat/ModelMixPanel.vue'
import { useAiConfigStore } from '@/stores/aiConfig'
import { configApi } from '@/services/api/configApi'
import type { AIModel } from '@/types/ai-models'

// The speed-config card is the user's one-tap model chooser: every mix must
// show what it would really activate on this installation, a mix the server
// cannot serve must be inert, and a tap must both apply and close.

const notifySuccess = vi.fn()
const notifyError = vi.fn()

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key, locale: { value: 'en' } }),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: notifySuccess, error: notifyError }),
}))

vi.mock('@/services/api/configApi', () => ({
  configApi: {
    getModels: vi.fn().mockResolvedValue({ success: true, models: {}, providers: [] }),
    getDefaultModels: vi.fn().mockResolvedValue({ success: true, defaults: {} }),
    saveDefaultModels: vi.fn().mockResolvedValue({ success: true, message: 'ok' }),
    resetDefaultModels: vi.fn().mockResolvedValue({ success: true, message: 'ok', defaults: {} }),
  },
}))

const model = (
  overrides: Partial<AIModel> & Pick<AIModel, 'id' | 'service' | 'name'>
): AIModel => ({
  tag: 'chat',
  providerId: '',
  quality: 1,
  rating: 1,
  priceIn: 0,
  priceOut: 0,
  description: null,
  isSystemModel: false,
  features: [],
  ...overrides,
})

const mountPanel = () =>
  mount(ModelMixPanel, {
    global: {
      stubs: {
        Icon: true,
        ModelMixIcon: true,
      },
    },
  })

describe('ModelMixPanel', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    localStorage.clear()
    useAiConfigStore().models = {
      CHAT: [
        model({
          id: 240,
          service: 'Anthropic',
          providerId: 'claude-fable-5',
          name: 'Claude Fable 5',
        }),
        model({ id: 326, service: 'xAI', providerId: 'grok-4.6', name: 'Grok 4.6' }),
      ],
      TEXT2PIC: [
        model({
          id: 316,
          service: 'xAI',
          providerId: 'grok-imagine-image',
          name: 'Grok Imagine Image',
          tag: 'text2pic',
        }),
      ],
    }
  })

  it('renders one row per mix, with resolved model names as the subtitle', () => {
    const wrapper = mountPanel()

    expect(wrapper.find('[data-testid="btn-model-mix-default"]').exists()).toBe(true)
    const grokRow = wrapper.get('[data-testid="btn-model-mix-xai"]')
    expect(grokRow.text()).toContain('Grok 4.6')
    expect(grokRow.text()).toContain('Grok Imagine Image')
  })

  it('disables mixes this installation cannot serve', () => {
    const wrapper = mountPanel()

    const openaiRow = wrapper.get('[data-testid="btn-model-mix-openai"]')
    expect(openaiRow.attributes('disabled')).toBeDefined()
    expect(openaiRow.text()).toContain('modelMix.unavailable')

    expect(
      wrapper.get('[data-testid="btn-model-mix-anthropic"]').attributes('disabled')
    ).toBeUndefined()
  })

  it('applies a mix on click, toasts, and emits select so the host closes', async () => {
    const wrapper = mountPanel()

    await wrapper.get('[data-testid="btn-model-mix-xai"]').trigger('click')
    await vi.waitFor(() => {
      expect(wrapper.emitted('select')).toBeTruthy()
    })

    expect(configApi.saveDefaultModels).toHaveBeenCalledWith({
      defaults: { CHAT: 326, TEXT2PIC: 316 },
    })
    expect(notifySuccess).toHaveBeenCalled()
    expect(wrapper.emitted('select')?.[0]).toEqual(['xai'])
  })

  it('shows an error toast and keeps the panel open when applying fails', async () => {
    vi.mocked(configApi.saveDefaultModels).mockRejectedValueOnce(new Error('boom'))
    const wrapper = mountPanel()

    await wrapper.get('[data-testid="btn-model-mix-xai"]').trigger('click')
    await vi.waitFor(() => {
      expect(notifyError).toHaveBeenCalled()
    })

    expect(wrapper.emitted('select')).toBeUndefined()
  })
})
