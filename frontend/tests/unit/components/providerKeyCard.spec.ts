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
    secretEnvVar: null,
    hasSecret: false,
    testable: true,
    chat: true,
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

  // NV05 (D2): media/speech providers live on the same card grid. A pair
  // provider gets a second password input; single-key providers never do.
  it('shows the secret input only for a key+secret provider and sends both halves', async () => {
    expect(mountCard().find('[data-testid="provider-secret-input-groq"]').exists()).toBe(false)

    saveProviderKey.mockResolvedValue({
      success: true,
      provider: 'higgsfield',
      maskedKey: 'hf••••abcd',
      defaultsApplied: false,
      tested: true,
    })
    const wrapper = mountCard({
      name: 'higgsfield',
      displayName: 'Higgsfield',
      envVar: 'HIGGSFIELD_API_KEY',
      secretEnvVar: 'HIGGSFIELD_API_SECRET',
      chat: false,
      recommended: false,
      freeTier: false,
    })

    const save = wrapper.get('[data-testid="provider-key-save-higgsfield"]')
    await wrapper.get('[data-testid="provider-key-input-higgsfield"]').setValue('hf-key')
    // Key alone is not enough for a pair provider.
    expect(save.attributes('disabled')).toBeDefined()

    await wrapper.get('[data-testid="provider-secret-input-higgsfield"]').setValue('hf-secret')
    expect(save.attributes('disabled')).toBeUndefined()
    await save.trigger('click')
    await flushPromises()

    expect(saveProviderKey).toHaveBeenCalledWith('higgsfield', 'hf-key', {
      applyDefaults: false,
      secret: 'hf-secret',
    })
    // No chat defaults for a media provider ⇒ no "use as default" affordance.
    expect(wrapper.find('[data-testid="provider-key-apply-defaults-higgsfield"]').exists()).toBe(
      false
    )
    expect(wrapper.find('[data-testid="provider-key-make-default-higgsfield"]').exists()).toBe(
      false
    )
  })

  it('shows the env/Helm badge for an environment key and does not look unsaved', () => {
    const wrapper = mountCard({
      configured: true,
      source: 'env',
      origin: null,
      maskedKey: 'gsk_••••envk',
    })

    expect(wrapper.get('[data-testid="provider-key-source-env-groq"]').text()).toContain(
      'environment'
    )
    expect(wrapper.text()).toContain('gsk_••••envk')
    expect(wrapper.text()).toContain('Connected')
  })

  it('says a key was saved but not tested when the provider offers no check', async () => {
    saveProviderKey.mockResolvedValue({
      success: true,
      provider: 'thehive',
      maskedKey: 'th••••abcd',
      defaultsApplied: false,
      tested: false,
    })
    const wrapper = mountCard({
      name: 'thehive',
      displayName: 'TheHive',
      envVar: 'THEHIVE_API_KEY',
      testable: false,
      chat: false,
      recommended: false,
      freeTier: false,
      configured: true,
      source: 'db',
      origin: 'ui',
      maskedKey: 'th••••abcd',
    })

    expect(wrapper.find('[data-testid="provider-key-test-thehive"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="provider-key-save-thehive"]').text()).toBe('Save')
    expect(wrapper.text()).toContain('Saved — not tested')

    await wrapper.get('[data-testid="provider-key-input-thehive"]').setValue('th-key')
    await wrapper.get('[data-testid="provider-key-save-thehive"]').trigger('click')
    await flushPromises()

    expect(notifications.success.mock.calls[0]?.[0]).toContain('not tested')
  })

  it('does not call an env-supplied untestable key “saved”', () => {
    const wrapper = mountCard({
      name: 'thehive',
      displayName: 'TheHive',
      envVar: 'THEHIVE_API_KEY',
      testable: false,
      chat: false,
      configured: true,
      source: 'env',
      origin: null,
      maskedKey: 'th••••envk',
    })

    expect(wrapper.find('[data-testid="provider-key-untested-thehive"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="provider-key-source-env-thehive"]').exists()).toBe(true)
  })
})
