import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import SetupDoneStep from '@/components/setup/SetupDoneStep.vue'

const reload = vi.fn()
const refreshUser = vi.fn()

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({ branding: { name: 'Synaplan' }, reload }),
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ refreshUser }),
}))

const invalidateSetupWizardRequired = vi.fn()
vi.mock('@/router/setupGate', () => ({
  invalidateSetupWizardRequired: () => invalidateSetupWizardRequired(),
}))

const notifyError = vi.fn()
vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ error: notifyError }),
}))

function mountStep(): { wrapper: ReturnType<typeof mount>; router: Router } {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/setup', component: { template: '<div />' } },
    ],
  })
  const wrapper = mount(SetupDoneStep, {
    global: { plugins: [router], stubs: { Icon: true } },
  })
  return { wrapper, router }
}

describe('SetupDoneStep', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    reload.mockResolvedValue(undefined)
    refreshUser.mockResolvedValue(undefined)
  })

  it('refreshes the stale runtime config before leaving, so the gate lets us out', async () => {
    const { wrapper, router } = mountStep()
    const replace = vi.spyOn(router, 'replace')

    await wrapper.get('[data-testid="setup-done-enter"]').trigger('click')
    await flushPromises()

    expect(invalidateSetupWizardRequired).toHaveBeenCalled()
    expect(reload).toHaveBeenCalled()
    expect(refreshUser).toHaveBeenCalled()
    expect(replace).toHaveBeenCalledWith('/')
    expect(reload.mock.invocationCallOrder[0]).toBeLessThan(replace.mock.invocationCallOrder[0])
  })

  it('stays put and lets the user retry when the config could not be refreshed', async () => {
    reload.mockRejectedValue(new Error('offline'))
    const { wrapper, router } = mountStep()
    const replace = vi.spyOn(router, 'replace')

    await wrapper.get('[data-testid="setup-done-enter"]').trigger('click')
    await flushPromises()

    expect(replace).not.toHaveBeenCalled()
    expect(notifyError).toHaveBeenCalledWith('offline')
    expect(wrapper.get('[data-testid="setup-done-enter"]').attributes('disabled')).toBeUndefined()
  })
})
