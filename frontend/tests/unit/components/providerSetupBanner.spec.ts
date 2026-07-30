import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ProviderSetupBanner from '@/components/setup/ProviderSetupBanner.vue'

const auth = { isAuthenticated: true, isAdmin: false }
const config = { setup: { chatReady: false as boolean | null } }

vi.mock('@/stores/auth', () => ({ useAuthStore: () => auth }))
vi.mock('@/stores/config', () => ({ useConfigStore: () => config }))

const mountBanner = () =>
  mount(ProviderSetupBanner, {
    global: { stubs: { Icon: true, RouterLink: { template: '<a><slot /></a>' } } },
  })

describe('ProviderSetupBanner', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    auth.isAuthenticated = true
    auth.isAdmin = false
    config.setup.chatReady = false
  })

  it('tells a regular user to contact an administrator', () => {
    const wrapper = mountBanner()

    expect(wrapper.find('[data-testid="provider-setup-banner"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="provider-setup-banner-cta"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('administrator')
  })

  it('offers admins a shortcut to the setup page', () => {
    auth.isAdmin = true
    const wrapper = mountBanner()

    expect(wrapper.find('[data-testid="provider-setup-banner-cta"]').exists()).toBe(true)
  })

  // The blocker this banner exists for is the inverse: it must disappear the
  // moment chat actually works, otherwise it nags forever.
  it('disappears once chat is ready', () => {
    config.setup.chatReady = true

    expect(mountBanner().find('[data-testid="provider-setup-banner"]').exists()).toBe(false)
  })

  it('stays hidden while readiness is still unknown', () => {
    config.setup.chatReady = null

    expect(mountBanner().find('[data-testid="provider-setup-banner"]').exists()).toBe(false)
  })

  it('stays hidden for anonymous visitors', () => {
    auth.isAuthenticated = false

    expect(mountBanner().find('[data-testid="provider-setup-banner"]').exists()).toBe(false)
  })

  it('can be dismissed for the session', async () => {
    const wrapper = mountBanner()

    await wrapper.find('[data-testid="provider-setup-banner-dismiss"]').trigger('click')

    expect(wrapper.find('[data-testid="provider-setup-banner"]').exists()).toBe(false)
  })
})
