import { describe, it, expect } from 'vitest'
import { isChannelSource } from '@/utils/channelSource'

describe('isChannelSource', () => {
  it('returns true for known channel tokens (case-insensitive)', () => {
    expect(isChannelSource('WHATSAPP')).toBe(true)
    expect(isChannelSource('whatsapp')).toBe(true)
    expect(isChannelSource('WhatsApp')).toBe(true)
    expect(isChannelSource('EMAIL')).toBe(true)
    expect(isChannelSource('WEB')).toBe(true)
    expect(isChannelSource('widget')).toBe(true)
    expect(isChannelSource('AI_WIDGET')).toBe(true)
    expect(isChannelSource('WORDPRESS')).toBe(true)
    expect(isChannelSource('HUMAN_OPERATOR')).toBe(true)
    expect(isChannelSource('SYSTEM')).toBe(true)
    expect(isChannelSource('PERF')).toBe(true)
    expect(isChannelSource('API')).toBe(true)
  })

  it('returns false for real AI provider names', () => {
    expect(isChannelSource('OpenAI')).toBe(false)
    expect(isChannelSource('Anthropic')).toBe(false)
    expect(isChannelSource('Groq')).toBe(false)
    expect(isChannelSource('Google')).toBe(false)
    expect(isChannelSource('Ollama')).toBe(false)
    expect(isChannelSource('Mistral')).toBe(false)
  })

  it('returns false for empty / nullish input', () => {
    expect(isChannelSource('')).toBe(false)
    expect(isChannelSource(null)).toBe(false)
    expect(isChannelSource(undefined)).toBe(false)
  })

  it('does not treat substrings as channel tokens', () => {
    expect(isChannelSource('whatsapp-bridge')).toBe(false)
    expect(isChannelSource('web-search')).toBe(false)
  })
})
