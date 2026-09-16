import { useI18n } from 'vue-i18n'
import { supportedLanguages, type SupportedLanguage } from '@/i18n'

export type SummarizeLength = 'short' | 'medium' | 'long'

export const SUMMARIZE_LENGTHS: readonly SummarizeLength[] = ['short', 'medium', 'long']

function isSupportedLanguage(value: string): value is SupportedLanguage {
  return (supportedLanguages as readonly string[]).includes(value)
}

export function useSummarizeTool() {
  const { t, locale } = useI18n()

  const defaultLanguage = (): SupportedLanguage => {
    const base = String(locale.value).toLowerCase().split('-')[0] ?? 'en'
    return isSupportedLanguage(base) ? base : 'en'
  }

  const lengthValueLabel = (length: SummarizeLength): string =>
    t(`chatInput.tools.summarizeLengthValue.${length}`)

  const languageLabel = (language: string): string => t(`chatInput.tools.summarizeLang.${language}`)

  const buildSummarizeInstruction = ({
    length,
    language,
  }: {
    length: SummarizeLength
    language: string
  }): string =>
    t('chatInput.tools.summarizeInstruction', {
      length: lengthValueLabel(length),
      language: languageLabel(language),
    })

  return {
    buildSummarizeInstruction,
    defaultLanguage,
    languageLabel,
    languageOptions: supportedLanguages,
    lengthOptions: SUMMARIZE_LENGTHS,
  }
}
