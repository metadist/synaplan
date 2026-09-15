import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

const mockLocale = ref('de')

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: Record<string, string>) => {
      const translations: Record<string, string> = {
        'chatInput.tools.summarizeInstruction':
          'Summarize the attached document. Length: {length}. Answer in {language}.',
        'chatInput.tools.summarizeLengthValue.short': 'short',
        'chatInput.tools.summarizeLengthValue.medium': 'medium',
        'chatInput.tools.summarizeLengthValue.long': 'long',
        'chatInput.tools.summarizeLang.de': 'German',
        'chatInput.tools.summarizeLang.en': 'English',
        'chatInput.tools.summarizeLang.es': 'Spanish',
        'chatInput.tools.summarizeLang.fr': 'French',
        'chatInput.tools.summarizeLang.tr': 'Turkish',
      }
      const template = translations[key] ?? key
      if (!params) {
        return template
      }
      return template.replace(/\{(\w+)\}/g, (_, name: string) => params[name] ?? `{${name}}`)
    },
    locale: mockLocale,
  }),
}))

import { useSummarizeTool } from '@/composables/useSummarizeTool'

describe('useSummarizeTool', () => {
  beforeEach(() => {
    mockLocale.value = 'de'
  })

  it('builds an instruction for each length and language', () => {
    const { buildSummarizeInstruction } = useSummarizeTool()

    expect(buildSummarizeInstruction({ length: 'short', language: 'en' })).toBe(
      'Summarize the attached document. Length: short. Answer in English.'
    )
    expect(buildSummarizeInstruction({ length: 'medium', language: 'de' })).toBe(
      'Summarize the attached document. Length: medium. Answer in German.'
    )
    expect(buildSummarizeInstruction({ length: 'long', language: 'fr' })).toBe(
      'Summarize the attached document. Length: long. Answer in French.'
    )
  })

  it('defaults the language to the UI locale', () => {
    mockLocale.value = 'es'
    expect(useSummarizeTool().defaultLanguage()).toBe('es')

    mockLocale.value = 'pt-BR'
    expect(useSummarizeTool().defaultLanguage()).toBe('en')
  })
})
