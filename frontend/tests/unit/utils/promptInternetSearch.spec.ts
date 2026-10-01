import { describe, it, expect } from 'vitest'
import { internetModeFromMetadata, applyInternetModeToMetadata } from '@/utils/promptInternetSearch'
import type { PromptMetadata } from '@/services/api/promptsApi'

describe('internetModeFromMetadata', () => {
  it('maps an absent tool_internet key to "auto" (the AI decides)', () => {
    expect(internetModeFromMetadata({})).toBe('auto')
    expect(internetModeFromMetadata(null)).toBe('auto')
    expect(internetModeFromMetadata(undefined)).toBe('auto')
  })

  it('reads a stored true as "auto" because a prompt cannot force a search', () => {
    expect(internetModeFromMetadata({ tool_internet: true })).toBe('auto')
  })

  it('maps an explicit false to "off"', () => {
    expect(internetModeFromMetadata({ tool_internet: false })).toBe('off')
  })

  it('honours the legacy tool_internet_search alias', () => {
    expect(internetModeFromMetadata({ tool_internet_search: true })).toBe('auto')
    expect(internetModeFromMetadata({ tool_internet_search: false })).toBe('off')
  })

  it('prefers the canonical key over the legacy alias', () => {
    expect(internetModeFromMetadata({ tool_internet: false, tool_internet_search: true })).toBe(
      'off'
    )
  })
})

describe('applyInternetModeToMetadata', () => {
  it('omits tool_internet for "auto" so the backend keeps the AI default', () => {
    const metadata: PromptMetadata = {}
    applyInternetModeToMetadata(metadata, 'auto')
    expect('tool_internet' in metadata).toBe(false)
  })

  it('clears a stored value when switching to "auto"', () => {
    const off: PromptMetadata = { tool_internet: false }
    applyInternetModeToMetadata(off, 'auto')
    expect('tool_internet' in off).toBe(false)

    const legacyOn: PromptMetadata = { tool_internet: true, tool_internet_search: true }
    applyInternetModeToMetadata(legacyOn, 'auto')
    expect('tool_internet' in legacyOn).toBe(false)
    expect('tool_internet_search' in legacyOn).toBe(false)
  })

  it('writes an explicit false for "off"', () => {
    const off: PromptMetadata = {}
    applyInternetModeToMetadata(off, 'off')
    expect(off.tool_internet).toBe(false)
  })

  it('round-trips every mode back to itself', () => {
    for (const mode of ['auto', 'off'] as const) {
      const metadata: PromptMetadata = {}
      applyInternetModeToMetadata(metadata, mode)
      expect(internetModeFromMetadata(metadata)).toBe(mode)
    }
  })
})
