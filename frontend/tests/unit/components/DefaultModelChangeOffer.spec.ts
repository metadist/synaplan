import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import DefaultModelChangeOffer from '@/components/DefaultModelChangeOffer.vue'
import { useAiConfigStore } from '@/stores/aiConfig'

const mockGetOffers = vi.fn()
const mockSave = vi.fn()
const mockSuccess = vi.fn()
const mockError = vi.fn()

vi.mock('@/services/api/defaultModelOfferApi', () => ({
  getDefaultModelOffers: (...args: unknown[]) => mockGetOffers(...args),
}))

vi.mock('@/services/api/configApi', () => ({
  saveDefaultModels: (...args: unknown[]) => mockSave(...args),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({
    success: (...args: unknown[]) => mockSuccess(...args),
    error: (...args: unknown[]) => mockError(...args),
  }),
}))

vi.mock('@/stores/history', () => ({
  useHistoryStore: () => ({ messages: [] }),
}))

const chatOffer = {
  capability: 'CHAT',
  modelId: 55,
  modelName: 'gpt 5.5',
  unavailableCount: 3,
  alternatives: [
    { id: 88, name: 'gpt 6 sol', service: 'OpenAI' },
    { id: 89, name: 'Claude', service: 'Anthropic' },
  ],
}

function mountOffer(props: Record<string, unknown> = {}) {
  return mount(DefaultModelChangeOffer, {
    props: { placement: 'chip', capability: 'CHAT', activeModelId: 55, ...props },
    global: {
      stubs: { RouterLink: { template: '<a><slot /></a>', props: ['to'] } },
    },
  })
}

describe('DefaultModelChangeOffer', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    sessionStorage.clear()
    mockGetOffers.mockResolvedValue({ success: true, offers: [chatOffer] })
    mockSave.mockResolvedValue({ success: true, message: 'saved' })
  })

  it('stays hidden until the active model is the one that keeps failing', async () => {
    const hidden = mountOffer({ activeModelId: 89 })
    await flushPromises()
    expect(hidden.find('[data-testid="banner-default-model-offer"]').exists()).toBe(false)
    hidden.unmount()

    const shown = mountOffer()
    await flushPromises()
    expect(shown.get('[data-testid="banner-default-model-offer"]').text()).toContain('gpt 5.5')
    expect(shown.get('[data-testid="banner-default-model-offer"]').text()).toContain('3')
    shown.unmount()
  })

  it('saves only that default when the person picks a replacement', async () => {
    const wrapper = mountOffer()
    await flushPromises()

    await wrapper.get('[data-testid="select-default-offer-CHAT"]').setValue('89')
    await wrapper.get('[data-testid="btn-default-offer-use-CHAT"]').trigger('click')
    await flushPromises()

    expect(mockSave).toHaveBeenCalledWith({ defaults: { CHAT: 89 } })
    expect(useAiConfigStore().defaults.CHAT).toBe(89)
    expect(mockSuccess).toHaveBeenCalledOnce()
    expect(wrapper.find('[data-testid="banner-default-model-offer"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('does not change the default when dismissed', async () => {
    const wrapper = mountOffer()
    await flushPromises()

    await wrapper.get('[data-testid="btn-default-offer-dismiss"]').trigger('click')

    expect(mockSave).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="banner-default-model-offer"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('lists every offered default on the settings page', async () => {
    const wrapper = mountOffer({ placement: 'page', capability: null, activeModelId: null })
    await flushPromises()

    expect(wrapper.get('[data-testid="offer-default-CHAT"]').text()).toContain(
      'Change your Chat / General AI default?'
    )
    wrapper.unmount()
  })

  it('points at AI settings when nothing else can take over', async () => {
    mockGetOffers.mockResolvedValue({
      success: true,
      offers: [{ ...chatOffer, alternatives: [] }],
    })
    const wrapper = mountOffer()
    await flushPromises()

    expect(wrapper.find('[data-testid="btn-default-offer-use-CHAT"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="link-default-offer-settings"]').text()).toContain(
      'AI settings'
    )
    wrapper.unmount()
  })
})
