import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { asI18nSchema, loadAllMessages } from '@/i18n/loadAllMessages'
import PromptMarkdownField from '@/components/admin/PromptMarkdownField.vue'

const en = loadAllMessages('en')
const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: asI18nSchema(en) } })

const mountField = (modelValue: string) =>
  mount(PromptMarkdownField, {
    props: {
      modelValue,
      label: 'Prompt',
      testid: 'textarea-prompt-1',
    },
    global: { plugins: [i18n] },
  })

describe('PromptMarkdownField', () => {
  it('keeps the raw markdown and renders a preview of it', async () => {
    const wrapper = mountField('# Hello prompt')

    const textarea = wrapper.get('[data-testid="textarea-prompt-1"]')
    expect((textarea.element as HTMLTextAreaElement).value).toBe('# Hello prompt')

    await wrapper.get('[data-testid="btn-prompt-preview-textarea-prompt-1"]').trigger('click')

    const preview = wrapper.get('[data-testid="preview-textarea-prompt-1"]')
    expect(preview.html()).toContain('<h1')
    expect(preview.text()).toContain('Hello prompt')
    expect((textarea.element as HTMLTextAreaElement).value).toBe('# Hello prompt')

    await wrapper.get('[data-testid="btn-prompt-write-textarea-prompt-1"]').trigger('click')
    await textarea.setValue('# Hello prompt\n\n**kept**')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['# Hello prompt\n\n**kept**'])
  })

  it('shows an empty preview sentence when there is no text', async () => {
    const wrapper = mountField('   ')
    await wrapper.get('[data-testid="btn-prompt-preview-textarea-prompt-1"]').trigger('click')
    expect(wrapper.get('[data-testid="preview-textarea-prompt-1"]').text()).toContain(
      'Nothing to preview yet'
    )
  })
})
