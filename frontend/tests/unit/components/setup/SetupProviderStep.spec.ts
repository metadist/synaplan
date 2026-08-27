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
  freeTier: false,
  source: 'db',
  origin: null,
  maskedKey: '',
  consoleUrl: '',
  envVar: '',
  ...overrides,
})

const mountStep = () =>
  mount(SetupProviderStep, {
    global: {
      stubs: {
        SetupProviderKeyForm: {
          name: 'SetupProviderKeyForm',
          template: '<div class="key-form" />',
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
        provider('groq', { freeTier: true }),
        provider('xai'),
      ],
      defaultChatProvider: 'anthropic',
    })
  })

  it('shows every provider as a tile, so nothing is hidden behind a "show more"', async () => {
    const wrapper = mountStep()
    await flushPromises()

    expect(wrapper.findAllComponents({ name: 'SetupProviderTile' })).toHaveLength(4)
    expect(wrapper.find('[data-testid="setup-provider-grid"]').exists()).toBe(true)
  })

  it('reveals the key field only for the provider that was clicked', async () => {
    const wrapper = mountStep()
    await flushPromises()

    expect(wrapper.find('.key-form').exists()).toBe(false)
    expect(wrapper.find('[data-testid="setup-provider-pick-hint"]').exists()).toBe(true)

    await wrapper.get('[data-testid="setup-provider-tile-groq"]').trigger('click')

    const form = wrapper.getComponent({ name: 'SetupProviderKeyForm' })
    expect(form.props('provider')).toMatchObject({ name: 'groq' })
    expect(form.props('isDefaultChat')).toBe(false)
    expect(wrapper.find('[data-testid="setup-provider-pick-hint"]').exists()).toBe(false)
  })

  it('tells the form when the picked provider already holds the default', async () => {
    const wrapper = mountStep()
    await flushPromises()

    await wrapper.get('[data-testid="setup-provider-tile-anthropic"]').trigger('click')

    expect(wrapper.getComponent({ name: 'SetupProviderKeyForm' }).props('isDefaultChat')).toBe(true)
  })

  it('closes the panel when the same tile is clicked again', async () => {
    const wrapper = mountStep()
    await flushPromises()

    await wrapper.get('[data-testid="setup-provider-tile-groq"]').trigger('click')
    expect(wrapper.find('.key-form').exists()).toBe(true)

    await wrapper.get('[data-testid="setup-provider-tile-groq"]').trigger('click')
    expect(wrapper.find('.key-form').exists()).toBe(false)
  })

  it('switches the panel straight over to another provider', async () => {
    const wrapper = mountStep()
    await flushPromises()

    await wrapper.get('[data-testid="setup-provider-tile-groq"]').trigger('click')
    await wrapper.get('[data-testid="setup-provider-tile-xai"]').trigger('click')

    expect(wrapper.findAll('.key-form')).toHaveLength(1)
    expect(wrapper.getComponent({ name: 'SetupProviderKeyForm' }).props('provider')).toMatchObject({
      name: 'xai',
    })
  })

  it('collapses the panel and re-reads the list after a key was saved', async () => {
    const wrapper = mountStep()
    await flushPromises()
    await wrapper.get('[data-testid="setup-provider-tile-groq"]').trigger('click')

    listProviderKeys.mockResolvedValue({
      providers: [provider('groq', { configured: true, freeTier: true })],
      defaultChatProvider: 'groq',
    })
    wrapper.getComponent({ name: 'SetupProviderKeyForm' }).vm.$emit('saved')
    await flushPromises()

    expect(wrapper.find('.key-form').exists()).toBe(false)
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
    expect(wrapper.find('[data-testid="setup-provider-grid"]').exists()).toBe(false)
    await wrapper.get('[data-testid="setup-provider-continue"]').trigger('click')
    expect(wrapper.emitted('next')).toHaveLength(1)
  })
})
