import { describe, it, expect } from 'vitest'
import { getProviderIcon } from '@/utils/providerIcons'

describe('Provider Icons Utility', () => {
  it('should return OpenAI icon for openai service', () => {
    expect(getProviderIcon('openai')).toBe('simple-icons:openai')
    expect(getProviderIcon('OpenAI')).toBe('simple-icons:openai')
  })

  it('should return Anthropic icon for anthropic service', () => {
    expect(getProviderIcon('anthropic')).toBe('simple-icons:anthropic')
    expect(getProviderIcon('Anthropic')).toBe('simple-icons:anthropic')
  })

  it('should return Google icon for google service', () => {
    expect(getProviderIcon('google')).toBe('logos:google-icon')
    expect(getProviderIcon('Google AI')).toBe('logos:google-icon')
  })

  it('should return Groq icon for groq service', () => {
    expect(getProviderIcon('groq')).toBe('simple-icons:groq')
  })

  it('should return Ollama icon for ollama service', () => {
    expect(getProviderIcon('ollama')).toBe('simple-icons:ollama')
  })

  it('should return Stability AI icon for stability service', () => {
    expect(getProviderIcon('stability')).toBe('simple-icons:stabilityai')
  })

  it('should return ElevenLabs icon for elevenlabs service', () => {
    expect(getProviderIcon('elevenlabs')).toBe('simple-icons:elevenlabs')
  })

  it('should return Runway icon for runway service', () => {
    expect(getProviderIcon('runway')).toBe('mdi:runway')
  })

  it('should return default robot icon for unknown service', () => {
    expect(getProviderIcon('unknown')).toBe('mdi:robot')
    expect(getProviderIcon('')).toBe('mdi:robot')
  })

  it('should be case insensitive', () => {
    expect(getProviderIcon('OPENAI')).toBe('simple-icons:openai')
    expect(getProviderIcon('AnThRoPiC')).toBe('simple-icons:anthropic')
  })
})
