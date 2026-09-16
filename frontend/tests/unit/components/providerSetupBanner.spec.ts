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
    global: {
      stubs: {
        Icon: true,
        RouterLink: { template: '<a :href="to"><slot /></a>', props: ['to'] },
      },
    },
  })

describe('ProviderSetupBanner', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    auth.isAuthenticated = true
    auth.isAdmin = false
    config.setup.chatReady = false
  })

  it('points a regular user at the public documentation', () => {
    const wrapper = mountBanner()

    expect(wrapper.find('[data-testid="provider-setup-tombstone"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="provider-setup-tombstone-cta"]').exists()).toBe(false)
    const docs = wrapper.find('[data-testid="provider-setup-tombstone-docs"]')
    expect(docs.exists()).toBe(true)
    expect(docs.attributes('href')).toBe('https://docs.synaplan.com/')
    expect(wrapper.text()).toContain('administrator')
  })

  it('sends admins to the AI provider setup page', () => {
    auth.isAdmin = true
    const wrapper = mountBanner()

    const cta = wrapper.find('[data-testid="provider-setup-tombstone-cta"]')
    expect(cta.exists()).toBe(true)
    expect(cta.attributes('href')).toBe('/admin/setup')
    expect(wrapper.find('[data-testid="provider-setup-tombstone-docs"]').exists()).toBe(false)
  })

  it('disappears once chat is ready', () => {
    config.setup.chatReady = true

    expect(mountBanner().find('[data-testid="provider-setup-tombstone"]').exists()).toBe(false)
  })

  it('stays hidden while readiness is still unknown', () => {
    config.setup.chatReady = null

    expect(mountBanner().find('[data-testid="provider-setup-tombstone"]').exists()).toBe(false)
  })

  it('stays hidden for anonymous visitors', () => {
    auth.isAuthenticated = false

    expect(mountBanner().find('[data-testid="provider-setup-tombstone"]').exists()).toBe(false)
  })
})
