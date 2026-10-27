import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import StepPicker from '@/components/config/workflows/StepPicker.vue'
import { emptyStep } from '@/components/config/workflows/stepTypes'

const i18n = createI18n({ legacy: false, locale: 'en', missingWarn: false, fallbackWarn: false })

const mountPicker = (notion: boolean) =>
  mount(StepPicker, {
    props: { modelValue: '', notion },
    global: { plugins: [i18n], stubs: { Icon: true } },
  })

describe('StepPicker Notion step', () => {
  it('offers "Save to Notion" only while Notion is connected', () => {
    expect(mountPicker(false).find('[data-testid="btn-step-kind-save_to_notion"]').exists()).toBe(
      false
    )
    expect(mountPicker(true).find('[data-testid="btn-step-kind-save_to_notion"]').exists()).toBe(
      true
    )
  })

  it('starts the step with an empty page link and title', () => {
    expect(emptyStep('save_to_notion', 1).params).toMatchObject({ page: '', title: '' })
  })
})
