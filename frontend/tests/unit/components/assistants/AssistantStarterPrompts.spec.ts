import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import AssistantStarterPrompts from '@/components/assistants/AssistantStarterPrompts.vue'
import en from '@/i18n/en.json'

function mountPrompts(prompts: string[]) {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(AssistantStarterPrompts, {
    props: { prompts },
    global: { plugins: [i18n] },
  })
}

describe('AssistantStarterPrompts', () => {
  it('hides when there are no prompts', () => {
    const wrapper = mountPrompts([])
    expect(wrapper.find('[data-testid="comp-assistant-starters"]').exists()).toBe(false)
  })

  it('emits the chosen starter', async () => {
    const wrapper = mountPrompts(['Review this NDA'])
    await wrapper.get('[data-testid="btn-assistant-starter-0"]').trigger('click')
    expect(wrapper.emitted('pick')).toEqual([['Review this NDA']])
  })
})
