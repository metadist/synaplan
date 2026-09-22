import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { asI18nSchema, loadAllMessages } from '@/i18n/loadAllMessages'
import AdminPromptsPanel from '@/components/admin/AdminPromptsPanel.vue'
import type { SystemPrompt } from '@/services/api/adminApi'

const en = loadAllMessages('en')
const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: asI18nSchema(en) } })

const getSystemPrompts = vi.fn()
const updatePrompt = vi.fn()
const notifySuccess = vi.fn()
const notifyError = vi.fn()
const confirm = vi.fn()

vi.mock('@/services/api/adminApi', () => ({
  adminApi: {
    getSystemPrompts: (...args: unknown[]) => getSystemPrompts(...args),
    updatePrompt: (...args: unknown[]) => updatePrompt(...args),
  },
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: notifySuccess, error: notifyError }),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ confirm: (...args: unknown[]) => confirm(...args) }),
}))

vi.mock('@/composables/useMarkdown', () => ({
  useMarkdown: () => ({
    render: (markdown: string) => markdown.replace(/^# (.+)$/m, '<h1>$1</h1>'),
  }),
}))

const prompts: SystemPrompt[] = [
  {
    id: 7,
    topic: 'general',
    language: 'en',
    shortDescription: 'Plain answers',
    prompt: '# Answer clearly',
    selectionRules: 'Use for chat',
  },
  {
    id: 8,
    topic: 'officemaker',
    language: 'de',
    shortDescription: 'Office files',
    prompt: '# Build a document',
    selectionRules: null,
  },
]

const mountPanel = () =>
  mount(AdminPromptsPanel, {
    global: {
      plugins: [i18n],
      stubs: { Icon: true },
    },
  })

describe('AdminPromptsPanel', () => {
  beforeEach(() => {
    getSystemPrompts.mockReset()
    updatePrompt.mockReset()
    notifySuccess.mockReset()
    notifyError.mockReset()
    confirm.mockReset()
    confirm.mockResolvedValue(true)
    getSystemPrompts.mockResolvedValue({ prompts })
  })

  it('folds every prompt by default and previews markdown when opened', async () => {
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.get('[data-testid="prompt-section-7"]').attributes('data-open')).toBe('false')
    expect(wrapper.get('[data-testid="prompt-section-8"]').attributes('data-open')).toBe('false')
    expect(wrapper.get('#prompt-section-7-body').attributes('style') ?? '').toContain(
      'display: none'
    )

    await wrapper.get('[data-testid="btn-prompt-section-7"]').trigger('click')

    expect(wrapper.get('[data-testid="prompt-section-7"]').attributes('data-open')).toBe('true')
    expect(wrapper.get('[data-testid="prompt-preview-7"]').html()).toContain(
      '<h1>Answer clearly</h1>'
    )
  })

  it('saves the raw markdown from the editor and shows the new preview', async () => {
    const saved: SystemPrompt = {
      ...prompts[0],
      prompt: '# Answer clearly\n\n**Saved**',
    }
    updatePrompt.mockResolvedValue({ success: true, prompt: saved })

    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.get('[data-testid="btn-edit-prompt-7"]').trigger('click')
    const textarea = wrapper.get('[data-testid="textarea-prompt-7"]')
    await textarea.setValue('# Answer clearly\n\n**Saved**')
    await wrapper.get('[data-testid="btn-prompt-preview-textarea-prompt-7"]').trigger('click')
    expect(wrapper.get('[data-testid="preview-textarea-prompt-7"]').html()).toContain(
      '<h1>Answer clearly</h1>'
    )

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(updatePrompt).toHaveBeenCalledWith(7, {
      shortDescription: 'Plain answers',
      prompt: '# Answer clearly\n\n**Saved**',
      selectionRules: 'Use for chat',
    })
    expect(notifySuccess).toHaveBeenCalledWith('Saved the prompt for general.')
    expect(wrapper.get('[data-testid="prompt-preview-7"]').html()).toContain(
      '<h1>Answer clearly</h1>'
    )
  })

  it('keeps the draft when saving fails', async () => {
    updatePrompt.mockRejectedValue(new Error('nope'))

    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.get('[data-testid="btn-edit-prompt-7"]').trigger('click')
    await wrapper.get('[data-testid="textarea-prompt-7"]').setValue('# Still here')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(notifyError).toHaveBeenCalled()
    expect(
      (wrapper.get('[data-testid="textarea-prompt-7"]').element as HTMLTextAreaElement).value
    ).toBe('# Still here')
  })
})
