import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import SettingsPhoneIndex from '@/components/settings/SettingsPhoneIndex.vue'
import SettingsView from '@/views/SettingsView.vue'
import { PHONE_CHROME_MQ } from '@/composables/usePhoneChrome'
import { useAuthStore } from '@/stores/auth'

function stubPhoneChrome(phone: boolean) {
  vi.spyOn(window, 'matchMedia').mockImplementation(
    (query: string) =>
      ({
        matches: phone && query === PHONE_CHROME_MQ,
        media: query,
        addEventListener: () => {},
        removeEventListener: () => {},
      }) as unknown as MediaQueryList
  )
}

function settingsRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/settings', name: 'settings', component: { template: '<div />' } },
      { path: '/settings/:section', name: 'settings-section', component: { template: '<div />' } },
    ],
  })
}

describe('phone settings list', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    useAuthStore().user = { id: 1, email: 'a@b.c', level: 'NEW' }
    vi.restoreAllMocks()
  })

  it('lists the same sections as the desktop sidebar, with delete set apart', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', component: { template: '<div />' } },
        { path: '/settings/:section', component: { template: '<div />' } },
      ],
    })
    await router.push('/')
    await router.isReady()

    const wrapper = mount(SettingsPhoneIndex, {
      global: { plugins: [router] },
    })

    const ids = wrapper
      .findAll('[data-testid^="link-phone-settings-"]')
      .map((link) => link.attributes('data-testid'))
    expect(ids).toEqual([
      'link-phone-settings-profile',
      'link-phone-settings-appearance',
      'link-phone-settings-chat',
      'link-phone-settings-data',
      'link-phone-settings-security',
      'link-phone-settings-legal',
      'link-phone-settings-delete',
    ])
    expect(wrapper.get('[data-testid="link-phone-settings-appearance"]').attributes('href')).toBe(
      '/settings/appearance'
    )
    expect(wrapper.findAll('.surface-card')).toHaveLength(2)
  })

  it('opens the list from Preferences and links back from a section', async () => {
    stubPhoneChrome(true)
    const pinia = createPinia()
    setActivePinia(pinia)
    useAuthStore().user = { id: 1, email: 'a@b.c', level: 'NEW' }
    const router = settingsRouter()
    await router.push('/settings')
    await router.isReady()

    const wrapper = mount(SettingsView, {
      global: {
        plugins: [pinia, router],
        stubs: {
          MainLayout: { template: '<div><slot /></div>' },
          PageHeader: true,
          SettingsAppearanceSection: true,
          SettingsAccountPage: true,
        },
      },
    })
    await flushPromises()

    expect(wrapper.find('[data-testid="nav-phone-settings"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="link-settings-index"]').exists()).toBe(false)

    await router.push('/settings/appearance')
    await flushPromises()

    const back = wrapper.get('[data-testid="link-settings-index"]')
    expect(back.attributes('href')).toBe('/settings')
    expect(wrapper.find('[data-testid="nav-phone-settings"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('leaves the list for profile when the viewport becomes the desktop rail', async () => {
    stubPhoneChrome(false)
    const pinia = createPinia()
    setActivePinia(pinia)
    useAuthStore().user = { id: 1, email: 'a@b.c', level: 'NEW' }
    const router = settingsRouter()
    await router.push('/settings')
    await router.isReady()

    const wrapper = mount(SettingsView, {
      global: {
        plugins: [pinia, router],
        stubs: {
          MainLayout: { template: '<div><slot /></div>' },
          PageHeader: true,
          SettingsAppearanceSection: true,
          SettingsAccountPage: true,
        },
      },
    })
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/settings/profile')
    expect(wrapper.find('[data-testid="nav-phone-settings"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
