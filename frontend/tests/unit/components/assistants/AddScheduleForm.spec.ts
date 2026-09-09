import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import AddScheduleForm from '@/components/assistants/AddScheduleForm.vue'
import en from '@/i18n/en.json'

describe('AddScheduleForm', () => {
  it('keeps cron out of the primary form', () => {
    const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
    const wrapper = mount(AddScheduleForm, { global: { plugins: [i18n] } })
    expect(wrapper.get('[data-testid="form-add-schedule"]').text().toLowerCase()).not.toContain(
      'cron'
    )
    expect(wrapper.find('[data-testid="input-schedule-cron"]').exists()).toBe(true)
  })

  it('requires an instruction', async () => {
    const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
    const wrapper = mount(AddScheduleForm, { global: { plugins: [i18n] } })
    expect(wrapper.get('[data-testid="btn-save-schedule"]').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-testid="input-schedule-instruction"]').setValue('Mail me a summary')
    expect(wrapper.get('[data-testid="btn-save-schedule"]').attributes('disabled')).toBeUndefined()
  })
})
