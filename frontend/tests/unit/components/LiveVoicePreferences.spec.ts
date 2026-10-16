import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import LiveVoicePreferences from '@/components/config/LiveVoicePreferences.vue'
import { captionsPreferred, setCaptionsPreferred } from '@/services/liveVoice/preferences'

const fetchLiveVoiceOptions = vi.hoisted(() => vi.fn())
const setLiveVoiceConsent = vi.hoisted(() => vi.fn())
const success = vi.hoisted(() => vi.fn())
const notifyError = vi.hoisted(() => vi.fn())

vi.mock('@/services/api/liveVoiceApi', () => ({
  fetchLiveVoiceOptions,
  setLiveVoiceConsent,
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success, error: notifyError }),
}))

describe('LiveVoicePreferences', () => {
  beforeEach(() => {
    fetchLiveVoiceOptions.mockReset()
    setLiveVoiceConsent.mockReset()
    success.mockReset()
    notifyError.mockReset()
    setCaptionsPreferred(true)
  })

  it('stores the captions choice on the native checkbox', async () => {
    fetchLiveVoiceOptions.mockResolvedValue({ consentGiven: false })
    const wrapper = mount(LiveVoicePreferences)
    await flushPromises()

    const box = wrapper.get('[data-testid="check-live-voice-captions"]')
    expect(box.classes()).not.toContain('checkbox-brand')
    expect((box.element as HTMLInputElement).checked).toBe(true)

    await box.setValue(false)

    expect(captionsPreferred()).toBe(false)
    expect(localStorage.getItem('synaplan.liveVoice.captions')).toBe('0')
    expect(wrapper.find('[data-testid="btn-live-voice-withdraw"]').exists()).toBe(false)
  })

  it('withdraws permission so the next conversation asks again', async () => {
    fetchLiveVoiceOptions.mockResolvedValue({ consentGiven: true })
    setLiveVoiceConsent.mockResolvedValue({ given: false })
    const wrapper = mount(LiveVoicePreferences)
    await flushPromises()

    expect(wrapper.text()).toContain('Chat will ask again before the next conversation')
    await wrapper.get('[data-testid="btn-live-voice-withdraw"]').trigger('click')
    await flushPromises()

    expect(setLiveVoiceConsent).toHaveBeenCalledWith(false)
    expect(success).toHaveBeenCalledWith('Live conversations will ask for permission again.')
    expect(wrapper.find('[data-testid="btn-live-voice-withdraw"]').exists()).toBe(false)
  })
})
