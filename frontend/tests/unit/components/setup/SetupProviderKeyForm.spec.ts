import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import SetupProviderKeyForm from '@/components/setup/SetupProviderKeyForm.vue'
import type { ProviderKeyStatus } from '@/services/api/providerKeysApi'

const saveProviderKey = vi.fn()
const notifySuccess = vi.fn()
const notifyError = vi.fn()

vi.mock('@/services/api/providerKeysApi', () => ({
  saveProviderKey: (...args: unknown[]) => saveProviderKey(...args),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: notifySuccess, error: notifyError }),
}))

const provider = (overrides: Partial<ProviderKeyStatus> = {}): ProviderKeyStatus => ({
  name: 'groq',
  displayName: 'Groq',
  configured: false,
  recommended: true,
  freeTier: true,
  source: 'none',
  origin: null,
  maskedKey: '',
  consoleUrl: 'https://console.groq.com/keys',
  envVar: 'GROQ_API_KEY',
  ...overrides,
})

const mountForm = (overrides: Partial<ProviderKeyStatus> = {}, isDefaultChat = false) =>
  mount(SetupProviderKeyForm, {
    props: { provider: provider(overrides), isDefaultChat },
    global: { stubs: { ServiceIcon: true, Icon: true } },
  })

describe('SetupProviderKeyForm', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    saveProviderKey.mockResolvedValue({ defaultsApplied: true })
  })

  it('keeps the save button unusable until a key is typed', async () => {
    const wrapper = mountForm()
    const button = wrapper.get('[data-testid="setup-provider-save"]')

    expect(button.attributes('disabled')).toBeDefined()

    await wrapper.get('[data-testid="setup-provider-key-input-groq"]').setValue('gsk_test')

    expect(button.attributes('disabled')).toBeUndefined()
  })

  it('claims the default for the provider the operator just picked', async () => {
    const wrapper = mountForm()
    await wrapper.get('[data-testid="setup-provider-key-input-groq"]').setValue('gsk_test')
    await wrapper.get('[data-testid="setup-provider-save"]').trigger('click')
    await flushPromises()

    expect(saveProviderKey).toHaveBeenCalledWith('groq', 'gsk_test', { applyDefaults: true })
    expect(wrapper.emitted('saved')).toHaveLength(1)
  })

  it('does not re-apply defaults to the provider that already holds them', async () => {
    const wrapper = mountForm({}, true)
    await wrapper.get('[data-testid="setup-provider-key-input-groq"]').setValue('gsk_test')
    await wrapper.get('[data-testid="setup-provider-save"]').trigger('click')
    await flushPromises()

    expect(saveProviderKey).toHaveBeenCalledWith('groq', 'gsk_test', { applyDefaults: false })
  })

  it('keeps the typed key and reports the failure when saving is rejected', async () => {
    saveProviderKey.mockRejectedValue(new Error('invalid key'))
    const wrapper = mountForm()
    const input = wrapper.get('[data-testid="setup-provider-key-input-groq"]')
    await input.setValue('gsk_wrong')
    await wrapper.get('[data-testid="setup-provider-save"]').trigger('click')
    await flushPromises()

    expect(notifyError).toHaveBeenCalledWith('invalid key')
    expect(wrapper.emitted('saved')).toBeUndefined()
    expect((input.element as HTMLInputElement).value).toBe('gsk_wrong')
  })

  it('warns that saving takes a key permanently away from .env', () => {
    const wrapper = mountForm({
      configured: true,
      source: 'db',
      origin: 'env',
      maskedKey: 'gsk_…az',
    })

    expect(wrapper.get('[data-testid="setup-provider-source-hint"]').text()).not.toBe('')
    expect(wrapper.get('[data-testid="setup-provider-masked-key"]').text()).toBe('gsk_…az')
  })

  it('says nothing about .env for a provider that has no key at all', () => {
    const wrapper = mountForm()

    expect(wrapper.find('[data-testid="setup-provider-source-hint"]').exists()).toBe(false)
  })

  it('links to the provider console so the key can be fetched', () => {
    const wrapper = mountForm()

    expect(wrapper.get('[data-testid="setup-provider-get-key"]').attributes('href')).toBe(
      'https://console.groq.com/keys'
    )
  })

  // A bare "free tier available" was read as a statement about Synaplan's own
  // pricing, so the hint has to name the provider that hands out the key.
  it('names the provider when saying the key is free', () => {
    expect(mountForm().get('[data-testid="setup-provider-free-tier"]').text()).toBe(
      'Getting an API key from Groq is free.'
    )
  })

  it('promises nothing about pricing for a provider without a free tier', () => {
    const wrapper = mountForm({ freeTier: false })

    expect(wrapper.find('[data-testid="setup-provider-free-tier"]').exists()).toBe(false)
  })
})
