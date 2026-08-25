import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import SetupProviderStep from '@/components/setup/SetupProviderStep.vue'

const listProviderKeys = vi.fn()

vi.mock('@/services/api/providerKeysApi', () => ({
  listProviderKeys: () => listProviderKeys(),
}))

const provider = (name: string, overrides: Record<string, unknown> = {}) => ({
  name,
  displayName: name,
  configured: false,
  recommended: false,
  source: 'db',
  ...overrides,
})

const mountStep = () =>
  mount(SetupProviderStep, {
    global: {
      stubs: {
        ProviderKeyCard: {
          template: '<div class="provider-card" />',
          props: ['provider', 'isDefaultChat'],
        },
      },
    },
  })

describe('SetupProviderStep', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    listProviderKeys.mockResolvedValue({
      providers: [
        provider('anthropic', { recommended: true }),
        provider('openai', { recommended: true }),
        provider('groq'),
        provider('xai'),
      ],
      defaultChatProvider: 'anthropic',
    })
  })

  it('shows only the recommended providers until the rest are asked for', async () => {
    const wrapper = mountStep()
    await flushPromises()

    expect(wrapper.findAll('.provider-card')).toHaveLength(2)
    expect(wrapper.get('[data-testid="setup-provider-show-all"]').text()).toContain('2')

    await wrapper.get('[data-testid="setup-provider-show-all"]').trigger('click')

    expect(wrapper.findAll('.provider-card')).toHaveLength(4)
    expect(wrapper.find('[data-testid="setup-provider-show-all"]').exists()).toBe(false)
  })

  it('keeps an already connected provider visible even when it is not recommended', async () => {
    listProviderKeys.mockResolvedValue({
      providers: [provider('groq', { configured: true }), provider('xai')],
      defaultChatProvider: 'groq',
    })
    const wrapper = mountStep()
    await flushPromises()

    expect(wrapper.findAll('.provider-card')).toHaveLength(1)
    expect(wrapper.find('[data-testid="setup-provider-ready"]').exists()).toBe(true)
  })

  it('lets the step be skipped, because a key can be added later', async () => {
    const wrapper = mountStep()
    await flushPromises()

    await wrapper.get('[data-testid="setup-provider-continue"]').trigger('click')

    expect(wrapper.emitted('next')).toHaveLength(1)
  })

  it('stays skippable when the provider list cannot be loaded', async () => {
    listProviderKeys.mockRejectedValue(new Error('provider list unavailable'))
    const wrapper = mountStep()
    await flushPromises()

    expect(wrapper.get('[data-testid="setup-provider-error"]').text()).toContain(
      'provider list unavailable'
    )
    await wrapper.get('[data-testid="setup-provider-continue"]').trigger('click')
    expect(wrapper.emitted('next')).toHaveLength(1)
  })
})
