import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import AddScheduleForm from '@/components/assistants/AddScheduleForm.vue'
import { resetAccountTimezone } from '@/composables/useAccountTimezone'
import { asI18nSchema, loadAllMessages } from '@/i18n/loadAllMessages'
import { browserTimezone } from '@/utils/zonedDay'

const { mockGetProfile, mockUpdateProfile } = vi.hoisted(() => ({
  mockGetProfile: vi.fn(),
  mockUpdateProfile: vi.fn(),
}))

vi.mock('@/services/api/profileApi', () => ({
  profileApi: {
    getProfile: (...args: unknown[]) => mockGetProfile(...args),
    updateProfile: (...args: unknown[]) => mockUpdateProfile(...args),
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    user: { id: 4 },
    isImpersonating: false,
  }),
}))

const en = loadAllMessages('en')

function mountForm() {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: asI18nSchema(en) } })
  return mount(AddScheduleForm, { global: { plugins: [i18n] } })
}

describe('AddScheduleForm', () => {
  beforeEach(() => {
    resetAccountTimezone()
    mockGetProfile.mockReset()
    mockUpdateProfile.mockReset()
    mockUpdateProfile.mockResolvedValue({ success: true })
    mockGetProfile.mockResolvedValue({
      success: true,
      profile: { timezone: 'Europe/Berlin' },
    })
  })

  it('keeps cron out of the primary form', async () => {
    const wrapper = mountForm()
    await flushPromises()
    expect(wrapper.get('[data-testid="form-add-schedule"]').text().toLowerCase()).not.toContain(
      'cron'
    )
    expect(wrapper.find('[data-testid="input-schedule-cron"]').exists()).toBe(true)
  })

  it('requires an instruction and the profile time zone', async () => {
    const wrapper = mountForm()
    await flushPromises()
    expect(wrapper.get('[data-testid="btn-save-schedule"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="schedule-timezone"]').text()).toContain('Europe/Berlin')
    await wrapper.get('[data-testid="input-schedule-instruction"]').setValue('Mail me a summary')
    expect(wrapper.get('[data-testid="btn-save-schedule"]').attributes('disabled')).toBeUndefined()
  })

  it('stores the profile time zone on the schedule', async () => {
    const wrapper = mountForm()
    await flushPromises()
    await wrapper.get('[data-testid="input-schedule-instruction"]').setValue('Mail me a summary')
    await wrapper.get('[data-testid="btn-save-schedule"]').trigger('click')

    const payload = wrapper.emitted('save')?.[0]?.[0] as { tz: string }
    expect(payload.tz).toBe('Europe/Berlin')
  })

  it('saves the device zone into the profile and uses it for the schedule', async () => {
    mockGetProfile.mockResolvedValue({ success: true, profile: { timezone: '' } })
    const wrapper = mountForm()
    await flushPromises()
    const device = browserTimezone()

    expect(mockUpdateProfile).toHaveBeenCalledWith({ timezone: device })
    expect(wrapper.get('[data-testid="schedule-timezone"]').text()).toContain(device)
    await wrapper.get('[data-testid="input-schedule-instruction"]').setValue('Mail me a summary')
    expect(wrapper.get('[data-testid="btn-save-schedule"]').attributes('disabled')).toBeUndefined()
    await wrapper.get('[data-testid="btn-save-schedule"]').trigger('click')

    const payload = wrapper.emitted('save')?.[0]?.[0] as { tz: string }
    expect(payload.tz).toBe(device)
  })

  it('still adds a schedule when the device zone could not be saved', async () => {
    mockGetProfile.mockResolvedValue({ success: true, profile: { timezone: '' } })
    mockUpdateProfile.mockRejectedValue(new Error('offline'))
    const wrapper = mountForm()
    await flushPromises()
    const device = browserTimezone()

    expect(wrapper.get('[data-testid="schedule-timezone"]').text()).toContain(
      'Your profile was not changed'
    )
    await wrapper.get('[data-testid="input-schedule-instruction"]').setValue('Mail me a summary')
    await wrapper.get('[data-testid="btn-save-schedule"]').trigger('click')

    const payload = wrapper.emitted('save')?.[0]?.[0] as { tz: string }
    expect(payload.tz).toBe(device)
  })
})
