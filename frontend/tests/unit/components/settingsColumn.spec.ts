import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

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
  it('centers one column instead of a side-by-side row', () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    useAuthStore().user = null

    const wrapper = mount(SettingsView, {
      global: {
        plugins: [pinia],
        stubs: {
          MainLayout: { template: '<div><slot /></div>' },
          PageHeader: true,
          SettingsAppearanceSection: true,
          SettingsAccountPage: true,
        },
      },
    })

    const column = wrapper.get('[data-testid="section-settings-column"]')
    expect(column.classes()).toEqual(expect.arrayContaining(['mx-auto', 'w-full', 'max-w-3xl']))
    expect(column.classes().join(' ')).toContain('@container')
    expect(wrapper.html()).not.toContain('md:flex-row')
    wrapper.unmount()
  })

  it('stacks the section index above the form', () => {
    const pinia = createPinia()
    setActivePinia(pinia)

    const wrapper = mount(SettingsAccountPage, {
      global: {
        plugins: [pinia],
        stubs: {
          SettingsSectionIndex: { template: '<nav data-testid="nav-settings-sections" />' },
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
    expect(root.classes()).toContain('space-y-6')
    const index = wrapper.get('[data-testid="nav-settings-sections"]')
    expect(root.element.firstElementChild).toBe(index.element)
    wrapper.unmount()
  })
})