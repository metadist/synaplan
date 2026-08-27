import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SetupProviderTile from '@/components/setup/SetupProviderTile.vue'
import type { ProviderKeyStatus } from '@/services/api/providerKeysApi'

const provider = (overrides: Partial<ProviderKeyStatus> = {}): ProviderKeyStatus => ({
  name: 'groq',
  displayName: 'Groq',
  configured: false,
  recommended: false,
  freeTier: false,
  source: 'none',
  origin: null,
  maskedKey: '',
  consoleUrl: '',
  envVar: '',
  ...overrides,
})

const mountTile = (overrides: Partial<ProviderKeyStatus> = {}, selected = false) =>
  mount(SetupProviderTile, {
    props: { provider: provider(overrides), selected },
    global: { stubs: { ServiceIcon: true, Icon: true } },
  })

describe('SetupProviderTile', () => {
  it('shows the name and nothing else for a plain provider', () => {
    const wrapper = mountTile()

    expect(wrapper.text()).toContain('Groq')
    expect(wrapper.text()).not.toContain('Recommended')
  })

  it('marks the recommended provider, the one question this screen has to answer', () => {
    expect(mountTile({ recommended: true }).text()).toContain('Recommended')
  })

  it('leaves the free-tier note to the key panel, where fetching a key happens', () => {
    expect(mountTile({ freeTier: true }).text()).not.toMatch(/free/i)
  })

  it('carries the connected state for assistive technology too', () => {
    // The check mark is aria-hidden, so without the text the state would be
    // invisible to a screen reader.
    expect(mountTile({ configured: true }).get('.sr-only').text()).toBe('Connected')
  })

  it('reports its selected state through aria-pressed', () => {
    expect(mountTile({}, false).attributes('aria-pressed')).toBe('false')
    expect(mountTile({}, true).attributes('aria-pressed')).toBe('true')
  })

  it('emits the pick instead of deciding anything itself', async () => {
    const wrapper = mountTile()

    await wrapper.get('[data-testid="setup-provider-tile-groq"]').trigger('click')

    expect(wrapper.emitted('select')).toHaveLength(1)
  })
})
