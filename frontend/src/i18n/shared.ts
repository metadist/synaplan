import { compile } from '@intlify/core-base'
import type { MessageCompiler, MessageFunction } from '@intlify/core-base'
import { isNativeApp } from '@/services/api/nativeRuntime'

export const supportedLanguages = ['de', 'en', 'es', 'fr', 'tr'] as const
export type SupportedLanguage = (typeof supportedLanguages)[number]

export const languageOptions: ReadonlyArray<{
  value: SupportedLanguage
  label: string
  flag: string
}> = [
  { value: 'de', label: 'Deutsch', flag: '🇩🇪' },
  { value: 'en', label: 'English', flag: '🇬🇧' },
  { value: 'es', label: 'Español', flag: '🇪🇸' },
  { value: 'fr', label: 'Français', flag: '🇫🇷' },
  { value: 'tr', label: 'Türkçe', flag: '🇹🇷' },
]

export function isSupportedLanguage(value: string): value is SupportedLanguage {
  return (supportedLanguages as readonly string[]).includes(value)
}

export function getInitialLanguage(): SupportedLanguage {
  if (typeof window === 'undefined') {
    return 'en'
  }

  const urlParams = new URLSearchParams(window.location.search)
  const urlLang = urlParams.get('lang')?.toLowerCase()
  if (urlLang && isSupportedLanguage(urlLang)) {
    localStorage.setItem('language', urlLang)
    return urlLang
  }

  const savedLanguage = localStorage.getItem('language')
  if (savedLanguage && isSupportedLanguage(savedLanguage)) {
    return savedLanguage
  }

  if (isNativeApp()) {
    return detectDeviceLanguage() ?? 'en'
  }

  return 'en'
}

export function detectDeviceLanguage(): SupportedLanguage | null {
  if (typeof navigator === 'undefined') {
    return null
  }

  const candidates = [...(navigator.languages ?? []), navigator.language].filter(
    (lang): lang is string => typeof lang === 'string' && lang !== ''
  )

  for (const candidate of candidates) {
    const base = candidate.toLowerCase().split('-')[0]
    if (isSupportedLanguage(base)) {
      return base
    }
  }

  return null
}

function rawTextMessage(text: string): MessageFunction<string> {
  const fn = Object.assign(() => text, { source: text })
  return fn as unknown as MessageFunction<string>
}

export const resilientMessageCompiler: MessageCompiler = (message, context) => {
  try {
    return compile(message, context)
  } catch (error) {
    if (typeof message !== 'string') {
      throw error
    }
    if (import.meta.env.DEV) {
      const reason = error instanceof Error ? error.message : String(error)
      console.warn(
        `[i18n] Could not compile message, rendering raw text instead: "${message}" (${reason})`
      )
    }
    return rawTextMessage(message)
  }
}

export function persistLanguage(lang: SupportedLanguage): void {
  localStorage.setItem('language', lang)
  if (typeof document !== 'undefined') {
    document.documentElement.lang = lang
  }
}
