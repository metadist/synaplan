import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import CookieConsent from '@/components/CookieConsent.vue'

const mockConfig = vi.hoisted(() => ({ googleTagEnabled: false }))

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({
    googleTag: {
      get enabled() {
        return mockConfig.googleTagEnabled
      },
    },
  }),
}))

const mountOptions = { global: { stubs: { Teleport: true } } }

describe('CookieConsent', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('should not show banner when googleTag is disabled', async () => {
    mockConfig.googleTagEnabled = false
    const wrapper = mount(CookieConsent, mountOptions)
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[data-testid="cookie-consent-banner"]').exists()).toBe(false)
  })

  it('should show banner when googleTag is enabled and no consent stored', async () => {
    mockConfig.googleTagEnabled = true
    const wrapper = mount(CookieConsent, mountOptions)
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[data-testid="cookie-consent-banner"]').exists()).toBe(true)
  })

  it('should not show banner when googleTag is enabled but consent already stored', async () => {
    mockConfig.googleTagEnabled = true
    localStorage.setItem(
      'cookie_consent',
      JSON.stringify({ version: '1', analytics: true, timestamp: Date.now() })
    )
    const wrapper = mount(CookieConsent, mountOptions)
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[data-testid="cookie-consent-banner"]').exists()).toBe(false)
  })
})
