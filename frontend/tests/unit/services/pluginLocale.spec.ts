import { describe, expect, it } from 'vitest'
import { lookupPluginString, pluginTranslator } from '@/services/pluginLocale'

describe('pluginTranslator', () => {
  const english = { title: 'Hello', page: { from: 'Started by {name}' } }
  const french = { title: 'Bonjour' }

  it('resolves the plugin key and falls back to English', () => {
    const t = pluginTranslator(french, english)
    expect(t('title')).toBe('Bonjour')
    expect(t('page.from', { name: 'Ada' })).toBe('Started by Ada')
  })

  it('returns the key when neither locale has it', () => {
    expect(pluginTranslator(french, english)('missing')).toBe('missing')
    expect(lookupPluginString(english, 'page.from')).toBe('Started by {name}')
  })
})
