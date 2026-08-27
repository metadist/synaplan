import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import SetupWizardView from '@/views/SetupWizardView.vue'

const getSetupState = vi.fn()
const isNativeServerControlAvailable = vi.fn()
const openNativeServerOverlay = vi.fn()

vi.mock('@/services/api/setupApi', () => ({
  getSetupState: (...args: unknown[]) => getSetupState(...args),
  createFirstAdmin: vi.fn(),
  completeSetup: vi.fn(),
}))

vi.mock('@/services/api/nativeServer', () => ({
  isNativeServerControlAvailable: () => isNativeServerControlAvailable(),
  openNativeServerOverlay: () => openNativeServerOverlay(),
}))

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({ branding: { name: 'Synaplan' } }),
}))

const refreshUser = vi.fn().mockResolvedValue(undefined)
const adoptCurrentSession = vi.fn()
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ refreshUser, adoptCurrentSession }),
}))

const virginState = {
  wizardRequired: true,
  adminExists: false,
  userCount: 0,
  mailerConfigured: true,
  access: {
    registrationEnabled: true,
    guestChatEnabled: true,
    registrationLocked: false,
    guestChatLocked: false,
  },
}

function mountView() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/login', component: { template: '<div />' } },
      { path: '/setup', name: 'setup', component: { template: '<div />' } },
    ],
  })
  return mount(SetupWizardView, {
    global: {
      plugins: [router],
      stubs: {
        SetupProviderStep: {
          name: 'SetupProviderStep',
          template: '<div data-testid="stub-provider" />',
        },
        SetupAccessStep: { name: 'SetupAccessStep', template: '<div data-testid="stub-access" />' },
        SetupDoneStep: { name: 'SetupDoneStep', template: '<div data-testid="stub-done" />' },
      },
    },
  })
}

describe('SetupWizardView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    isNativeServerControlAvailable.mockReturnValue(false)
    getSetupState.mockResolvedValue(virginState)
  })

  it('offers the language switch, because nobody has a profile yet', async () => {
    const wrapper = mountView()
    await flushPromises()

    const button = wrapper.get('[data-testid="setup-switch-language"]')
    const before = button.text()

    await button.trigger('click')

    expect(button.text()).not.toBe(before)
    expect(localStorage.getItem('language')).toBe(button.text().toLowerCase())
  })

  it('opens on the administrator step of a virgin installation', async () => {
    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.find('[data-testid="setup-step-admin"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="setup-progress"]').exists()).toBe(true)
  })

  it('hides the server switch on the web, where the address bar does the job', async () => {
    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.find('[data-testid="setup-switch-server"]').exists()).toBe(false)
  })

  it('offers the server switch as an emergency exit in the native app', async () => {
    isNativeServerControlAvailable.mockReturnValue(true)
    const wrapper = mountView()
    await flushPromises()

    await wrapper.get('[data-testid="setup-switch-server"]').trigger('click')

    expect(openNativeServerOverlay).toHaveBeenCalled()
  })

  it('advances to the provider step and syncs the new session', async () => {
    const wrapper = mountView()
    await flushPromises()

    wrapper.getComponent({ name: 'SetupAdminStep' }).vm.$emit('created')
    await flushPromises()

    expect(wrapper.find('[data-testid="stub-provider"]').exists()).toBe(true)
    expect(adoptCurrentSession).toHaveBeenCalled()
    expect(refreshUser).toHaveBeenCalled()
    expect(adoptCurrentSession.mock.invocationCallOrder[0]).toBeLessThan(
      refreshUser.mock.invocationCallOrder[0]
    )
  })

  it('stops claiming there is no administrator once one has been created', async () => {
    const wrapper = mountView()
    await flushPromises()

    const first = wrapper.get('[data-testid="setup-subtitle"]').text()

    wrapper.getComponent({ name: 'SetupAdminStep' }).vm.$emit('created')
    await flushPromises()

    const second = wrapper.get('[data-testid="setup-subtitle"]').text()
    expect(second).not.toBe(first)
    expect(second).toContain('2')
  })

  it('drops the subtitle and the lockdown promise on the completion screen', async () => {
    const wrapper = mountView()
    await flushPromises()

    wrapper.getComponent({ name: 'SetupAdminStep' }).vm.$emit('created')
    await flushPromises()
    wrapper.getComponent({ name: 'SetupProviderStep' }).vm.$emit('next')
    await flushPromises()
    wrapper.getComponent({ name: 'SetupAccessStep' }).vm.$emit('completed')
    await flushPromises()

    expect(wrapper.find('[data-testid="stub-done"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="setup-subtitle"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="setup-footer-hint"]').exists()).toBe(false)
  })

  it('points an already set-up instance at the login instead of the wizard', async () => {
    getSetupState.mockResolvedValue({ ...virginState, wizardRequired: false, adminExists: true })
    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.find('[data-testid="setup-already-done"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="setup-step-admin"]').exists()).toBe(false)
    // Would otherwise contradict the card right below it.
    expect(wrapper.find('[data-testid="setup-subtitle"]').exists()).toBe(false)
  })

  it('shows why the state could not be loaded instead of a blank card', async () => {
    getSetupState.mockRejectedValue(new Error('backend unreachable'))
    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.get('[data-testid="setup-load-error"]').text()).toContain('backend unreachable')
  })
})
