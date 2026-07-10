import { describe, it, expect } from 'vitest'
import { getProviderIcon, getProviderFlag } from '@/utils/providerIcons'

describe('Provider Icons Utility', () => {
  it('should return OpenAI icon for openai service', () => {
    expect(getProviderIcon('openai')).toBe('simple-icons:openai')
    expect(getProviderIcon('OpenAI')).toBe('simple-icons:openai')
  })

  it('should NOT return the OpenAI icon for the OpenAI-compatible (local) service', () => {
    // "openaicompatible" contains "openai" — it must be matched first so a
    // self-hosted/local endpoint never shows the OpenAI logo.
    expect(getProviderIcon('OpenAICompatible')).toBe('mdi:server-network')
    expect(getProviderIcon('openaicompatible')).toBe('mdi:server-network')
    expect(getProviderIcon('openai-compatible')).toBe('mdi:server-network')
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

  it('should return HuggingFace icon for huggingface service', () => {
    expect(getProviderIcon('huggingface')).toBe('simple-icons:huggingface')
    expect(getProviderIcon('HuggingFace')).toBe('simple-icons:huggingface')
    expect(getProviderIcon('Hugging Face')).toBe('simple-icons:huggingface')
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

describe('Provider Flag Utility', () => {
  it('should return the US flag for OpenAI', () => {
    expect(getProviderFlag('openai')).toBe('circle-flags:us')
  })

  it('should return a neutral world badge (not the US flag) for OpenAI-compatible', () => {
    expect(getProviderFlag('OpenAICompatible')).toBe('circle-flags:un')
    expect(getProviderFlag('openaicompatible')).toBe('circle-flags:un')
  })

  it('should return the German flag for self-hosted Ollama', () => {
    expect(getProviderFlag('ollama')).toBe('circle-flags:de')
  })
})
