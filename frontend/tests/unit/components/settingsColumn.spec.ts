import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

import SettingsAccountPage from '@/components/settings/SettingsAccountPage.vue'
import SettingsView from '@/views/SettingsView.vue'
import { useAuthStore } from '@/stores/auth'

vi.mock('@/composables/useProfileSettings', async () => {
  const { ref } = await import('vue')
  return {
    provideProfileSettings: () => ({
      loading: ref(false),
      profileLoaded: ref(true),
      profileLoadFailed: ref(false),
      loadProfile: vi.fn(),
      hasUnsavedChanges: ref(false),
      handleSave: vi.fn(),
      handleDiscard: vi.fn(),
    }),
  }
})

vi.mock('@/services/api/nativeServer', () => ({
  isNativeServerControlAvailable: () => false,
  isPurchaseAllowed: () => false,
}))

describe('settings column', () => {
  it('centers one column instead of a side-by-side row', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    useAuthStore().user = null
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/settings/:section', component: { template: '<div />' } }],
    })
    await router.push('/settings/appearance')
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

    const column = wrapper.get('[data-testid="section-settings-column"]')
    expect(column.classes()).toEqual(
      expect.arrayContaining(['mx-auto', 'w-full', 'max-w-[60rem]', '@container'])
    )
    expect(wrapper.html()).not.toContain('md:flex-row')
    expect(wrapper.html()).not.toContain('nav-settings-sections')
    wrapper.unmount()
  })

  it('shows one settings section and no in-page index', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/settings/:section', component: { template: '<div />' } }],
    })
    await router.push('/settings/profile')
    await router.isReady()

    const wrapper = mount(SettingsAccountPage, {
      global: {
        plugins: [pinia, router],
        stubs: {
          UnsavedChangesBar: true,
          NativeServerControl: true,
          ProfilePersonalSection: true,
          ProfileCompanySection: true,
          ProfileBillingSection: true,
          ProfileSecuritySection: true,
          ProfileLegalSection: true,
          ProfileDeleteSection: true,
          SettingsAppearanceSection: true,
          SettingsChatSection: true,
          SettingsDataSection: true,
        },
      },
    })

    const root = wrapper.get('div')
    expect(root.classes()).not.toContain('md:flex-row')
    expect(wrapper.find('[data-testid="nav-settings-sections"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="page-profile"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="section-language-settings"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
