import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ProviderKeyCard from '@/components/admin/ProviderKeyCard.vue'
import type { ProviderKeyStatus } from '@/services/api/providerKeysApi'

const saveProviderKey = vi.hoisted(() => vi.fn())
const deleteProviderKey = vi.hoisted(() => vi.fn())
const testProviderKey = vi.hoisted(() => vi.fn())
const applyProviderDefaults = vi.hoisted(() => vi.fn())

vi.mock('@/services/api/providerKeysApi', () => ({
  saveProviderKey,
  deleteProviderKey,
  testProviderKey,
  applyProviderDefaults,
}))

const confirm = vi.hoisted(() => vi.fn())
vi.mock('@/composables/useDialog', () => ({ useDialog: () => ({ confirm }) }))

const notifications = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))
vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: notifications.success, error: notifications.error }),
}))

const provider = (overrides: Partial<ProviderKeyStatus> = {}): ProviderKeyStatus =>
  ({
    name: 'groq',
    displayName: 'Groq',
    configured: false,
    source: 'none',
    origin: null,
    maskedKey: '',
    consoleUrl: 'https://console.groq.com/keys',
    envVar: 'GROQ_API_KEY',
    freeTier: true,
    recommended: true,
    ...overrides,
  }) as ProviderKeyStatus

const mountCard = (props: Partial<ProviderKeyStatus> = {}, isDefaultChat = false) =>
  mount(ProviderKeyCard, {
    props: { provider: provider(props), isDefaultChat },
    global: { stubs: { Icon: true, ProviderHelpHint: true } },
  })

describe('ProviderKeyCard', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('saves the entered key and reports that defaults were applied', async () => {
    saveProviderKey.mockResolvedValue({
      success: true,
      provider: 'groq',
      maskedKey: 'gsk_••••abcd',
      defaultsApplied: true,
    })
    const wrapper = mountCard()

    await wrapper.find('[data-testid="provider-key-input-groq"]').setValue('gsk_new_key')
    await wrapper.find('[data-testid="provider-key-save-groq"]').trigger('click')
    await flushPromises()

    // Not the default provider yet, so "apply defaults" is pre-checked.
    expect(saveProviderKey).toHaveBeenCalledWith('groq', 'gsk_new_key', { applyDefaults: true })
    expect(notifications.success).toHaveBeenCalled()
    expect(
      wrapper.find<HTMLInputElement>('[data-testid="provider-key-input-groq"]').element.value
    ).toBe('')
  })

  it('surfaces a rejected key instead of pretending it was saved', async () => {
    saveProviderKey.mockRejectedValue(new Error('The provider rejected this API key.'))
    const wrapper = mountCard()

    await wrapper.find('[data-testid="provider-key-input-groq"]').setValue('gsk_wrong')
    await wrapper.find('[data-testid="provider-key-save-groq"]').trigger('click')
    await flushPromises()

    expect(notifications.error).toHaveBeenCalledWith('The provider rejected this API key.')
    expect(notifications.success).not.toHaveBeenCalled()
  })

  it('does not offer to re-apply defaults for the provider that already is the default', async () => {
    saveProviderKey.mockResolvedValue({
      success: true,
      provider: 'groq',
      maskedKey: 'gsk_••••abcd',
      defaultsApplied: false,
    })
    const wrapper = mountCard({ configured: true, source: 'db', origin: 'ui' }, true)

    await wrapper.find('[data-testid="provider-key-input-groq"]').setValue('gsk_rotated')
    await wrapper.find('[data-testid="provider-key-save-groq"]').trigger('click')
    await flushPromises()

    expect(saveProviderKey).toHaveBeenCalledWith('groq', 'gsk_rotated', { applyDefaults: false })
  })

  // Deleting the DB row does not disable a provider whose env var is still set —
  // saying "removed" would be a half-truth.
  it('says the provider stays connected when an env key remains', async () => {
    confirm.mockResolvedValue(true)
    deleteProviderKey.mockResolvedValue({
      success: true,
      envFallbackActive: true,
      envVar: 'GROQ_API_KEY',
    })
    const wrapper = mountCard({ configured: true, source: 'db', origin: 'ui' })

    await wrapper.findAll('button').at(-1)?.trigger('click')
    await flushPromises()

    expect(deleteProviderKey).toHaveBeenCalledWith('groq')
    expect(notifications.success.mock.calls[0]?.[0]).toContain('GROQ_API_KEY')
  })

  it('keeps the stored key when the removal is not confirmed', async () => {
    confirm.mockResolvedValue(false)
    const wrapper = mountCard({ configured: true, source: 'db', origin: 'ui' })

    await wrapper.findAll('button').at(-1)?.trigger('click')
    await flushPromises()

    expect(deleteProviderKey).not.toHaveBeenCalled()
  })

  it('falls back to the catalog help URL when the API sends no console URL', () => {
    const wrapper = mountCard({ consoleUrl: '' })

    expect(wrapper.find('a').attributes('href')).toBeTruthy()
  })
})
