import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

/**
 * MOBILE-APP SEAM (first-run onboarding): the own-server modal keeps all server
 * logic (probe, persist, reload) behind the `nativeServer.ts` seam. This spec
 * pins the two paths the SPA controls: a rejected probe surfaces an error and
 * rolls the resume step back, and a successful save emits `saved`.
 */

const mockSave = vi.fn()
const mockSetResume = vi.fn()
const mockClearResume = vi.fn()

vi.mock('@/services/api/nativeServer', () => ({
  getNativeServerUrl: () => 'https://web.synaplan.com',
  getNativeDefaultServerUrl: () => 'https://web.synaplan.com',
  saveNativeServerUrl: (...args: unknown[]) => mockSave(...args),
}))

vi.mock('@/composables/useOnboarding', () => ({
  setOnboardingResumeStep: (...args: unknown[]) => mockSetResume(...args),
  clearOnboardingResumeStep: (...args: unknown[]) => mockClearResume(...args),
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

  it('surfaces an error and rolls back the resume step when the probe rejects', async () => {
    mockSave.mockResolvedValue({ ok: false, error: 'Server unreachable.' })
    const wrapper = mountModal()

    await wrapper.find('[data-testid="input-server-url"]').setValue('https://bad.example.com')
    await wrapper.find('[data-testid="btn-server-connect"]').trigger('click')
    await flushPromises()

    expect(mockSetResume).toHaveBeenCalledWith(1)
    expect(mockClearResume).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-testid="text-server-error"]').text()).toBe('Server unreachable.')
    expect(wrapper.emitted('saved')).toBeUndefined()
  })

  it('emits "saved" on a successful save (reload is imminent)', async () => {
    mockSave.mockResolvedValue({ ok: true })
    const wrapper = mountModal()

    await wrapper.find('[data-testid="input-server-url"]').setValue('https://good.example.com')
    await wrapper.find('[data-testid="btn-server-connect"]').trigger('click')
    await flushPromises()

    expect(mockSetResume).toHaveBeenCalledWith(1)
    expect(mockSave).toHaveBeenCalledWith('https://good.example.com')
    expect(mockClearResume).not.toHaveBeenCalled()
    expect(wrapper.emitted('saved')).toHaveLength(1)
  })
})
