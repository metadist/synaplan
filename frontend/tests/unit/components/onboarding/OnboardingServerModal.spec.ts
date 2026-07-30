import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

/**
 * MOBILE-APP SEAM (first-run onboarding): the own-server modal keeps all server
 * logic (probe, persist) behind the `nativeServer.ts` seam. `saveNativeServerUrl`
 * only persists — the modal is responsible for calling `reloadNativeApp()`
 * itself once the save succeeds. This spec pins the two paths the SPA
 * controls: a rejected probe surfaces an error and does not reload, and a
 * successful save emits `saved` and reloads.
 */

const mockSave = vi.fn()
const mockReload = vi.fn()

vi.mock('@/services/api/nativeServer', () => ({
  getNativeServerUrl: () => 'https://web.synaplan.com',
  getNativeDefaultServerUrl: () => 'https://web.synaplan.com',
  saveNativeServerUrl: (...args: unknown[]) => mockSave(...args),
  reloadNativeApp: (...args: unknown[]) => mockReload(...args),
}))

vi.mock('@iconify/vue', () => ({
  Icon: { template: '<i />' },
}))

import OnboardingServerModal from '@/components/onboarding/OnboardingServerModal.vue'

function mountModal() {
  return mount(OnboardingServerModal, {
    props: { isOpen: true },
    global: { stubs: { teleport: true } },
  })
}

describe('OnboardingServerModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('shows the currently connected server host', () => {
    const wrapper = mountModal()
    expect(wrapper.find('[data-testid="text-server-current"]').text()).toBe('web.synaplan.com')
  })

  it('does nothing when the input is empty (connect stays disabled)', async () => {
    const wrapper = mountModal()
    expect(wrapper.find('[data-testid="btn-server-connect"]').attributes('disabled')).toBeDefined()
    await wrapper.find('[data-testid="btn-server-connect"]').trigger('click')
    expect(mockSave).not.toHaveBeenCalled()
  })

  it('surfaces an error when the probe rejects', async () => {
    mockSave.mockResolvedValue({ ok: false, error: 'Server unreachable.' })
    const wrapper = mountModal()

    await wrapper.find('[data-testid="input-server-url"]').setValue('https://bad.example.com')
    await wrapper.find('[data-testid="btn-server-connect"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="text-server-error"]').text()).toBe('Server unreachable.')
    expect(wrapper.emitted('saved')).toBeUndefined()
    expect(mockReload).not.toHaveBeenCalled()
  })

  it('emits "saved" and reloads on a successful save', async () => {
    mockSave.mockResolvedValue({ ok: true })
    const wrapper = mountModal()

    await wrapper.find('[data-testid="input-server-url"]').setValue('https://good.example.com')
    await wrapper.find('[data-testid="btn-server-connect"]').trigger('click')
    await flushPromises()

    expect(mockSave).toHaveBeenCalledWith('https://good.example.com')
    expect(wrapper.emitted('saved')).toHaveLength(1)
    expect(mockReload).toHaveBeenCalledTimes(1)
  })
})
