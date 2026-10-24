import { describe, expect, it } from 'vitest'
import { catalogModelLabel, isWithdrawnModel } from '@/components/config/catalogModelLabel'

describe('catalogModelLabel', () => {
  it('keeps a unique name as the name only', () => {
    const echo = { name: 'Echo', service: 'Groq', json: { endpoint: 'api.groq.com' } }
    expect(catalogModelLabel(echo, [echo])).toBe('Echo')
  })

  it('disambiguates two rows that share a name', () => {
    const a = { name: 'Echo', service: 'Groq', json: { endpoint: 'api.groq.com' } }
    const b = { name: 'Echo', service: 'OpenAI', json: { endpoint: 'api.openai.com' } }
    expect(catalogModelLabel(a, [a, b])).toBe('Echo · Groq · api.groq.com')
    expect(catalogModelLabel(b, [a, b])).toBe('Echo · OpenAI · api.openai.com')
  })

  it('reads the withdrawn mark from import meta', () => {
    expect(
      isWithdrawnModel({
        name: 'Gone',
        service: 'Ollama',
        json: { meta: { import: { withdrawn: true } } },
      })
    ).toBe(true)
    expect(isWithdrawnModel({ name: 'Here', service: 'Ollama', json: {} })).toBe(false)
  })
})
