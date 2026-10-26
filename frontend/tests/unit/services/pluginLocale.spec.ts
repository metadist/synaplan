import { describe, expect, it } from 'vitest'
import { createLoadGate, lookupPluginString, pluginTranslator } from '@/services/pluginLocale'

describe('pluginTranslator', () => {
  const english = { title: 'Hello', page: { from: 'Started by {name}' } }
  const french = { title: 'Bonjour' }

  it('resolves the plugin key and falls back to English', () => {
    const t = pluginTranslator(french, english)
    expect(t('title')).toBe('Bonjour')
    expect(t('page.from', { name: 'Ada' })).toBe('Started by Ada')
  })

  it('ignores a load that finished after a newer one started', () => {
    const gate = createLoadGate()
    const first = gate.begin()
    const second = gate.begin()
    expect(gate.isCurrent(first)).toBe(false)
    expect(gate.isCurrent(second)).toBe(true)
    gate.cancel()
    expect(gate.isCurrent(second)).toBe(false)
  })

  it('returns the key when neither locale has it', () => {
    expect(pluginTranslator(french, english)('missing')).toBe('missing')
    expect(lookupPluginString(english, 'page.from')).toBe('Started by {name}')
  })
})
