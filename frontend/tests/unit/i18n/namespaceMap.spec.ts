import { describe, expect, it } from 'vitest'
import { loadAllMessages } from '@/i18n/loadAllMessages'
import {
  BUNDLE_PANEL_I18N_NAMESPACES,
  CHROME_I18N_NAMESPACES,
  I18N_NAMESPACES,
  NAMESPACE_KEYS,
} from '@/i18n/namespaces'
import { supportedLanguages } from '@/i18n'

const EXTRA_AUTH_KEYS = ['login', 'register', 'forgotPassword', 'verifyEmail', 'emailVerified']

function keyToNamespace(): Map<string, string> {
  const map = new Map<string, string>()
  for (const namespace of I18N_NAMESPACES) {
    for (const key of NAMESPACE_KEYS[namespace]) {
      map.set(key, namespace)
    }
  }
  for (const key of EXTRA_AUTH_KEYS) {
    map.set(key, 'auth')
  }
  return map
}

describe('i18n namespace map', () => {
  const map = keyToNamespace()

  it('assigns every English top-level key to exactly one namespace', () => {
    const englishKeys = Object.keys(loadAllMessages('en')).sort()
    const mappedEnglish = [...new Set(Object.values(NAMESPACE_KEYS).flat())].sort()

    expect(englishKeys).toEqual(mappedEnglish)
    expect(englishKeys.every((key) => map.has(key))).toBe(true)
  })

  it('round-trips every locale through the namespace map without leftover keys', () => {
    for (const locale of supportedLanguages) {
      const messages = loadAllMessages(locale)
      const unknown = Object.keys(messages).filter((key) => !map.has(key))
      expect(unknown, `${locale} has unmapped top-level keys`).toEqual([])
    }
  })

  it('keeps sidebar chrome namespaces on every authenticated route', () => {
    expect([...CHROME_I18N_NAMESPACES]).toEqual(['chat', 'auth', 'admin', 'settings'])
  })

  it('loads assistants wherever ExportImportPanel renders bundle.*', () => {
    expect([...BUNDLE_PANEL_I18N_NAMESPACES]).toEqual(['assistants'])
    expect(NAMESPACE_KEYS.assistants).toContain('bundle')
  })

  it('keeps dotted keys stable: config.savedTasks still lives under the config namespace', () => {
    const en = loadAllMessages('en') as { config?: { savedTasks?: { saveAsTask?: string } } }
    expect(en.config?.savedTasks?.saveAsTask).toBeTruthy()
  })
})
