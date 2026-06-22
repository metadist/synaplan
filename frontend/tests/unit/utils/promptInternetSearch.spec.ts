import { describe, it, expect } from 'vitest'
import { internetModeFromMetadata, applyInternetModeToMetadata } from '@/utils/promptInternetSearch'
import type { PromptMetadata } from '@/services/api/promptsApi'

describe('internetModeFromMetadata', () => {
  it('maps an absent tool_internet key to "auto" (classifier decides)', () => {
    // Issue #1138: a seeded prompt with no `tool_internet` row must read as
    // "auto", not silently as "off".
    expect(internetModeFromMetadata({})).toBe('auto')
    expect(internetModeFromMetadata(null)).toBe('auto')
    expect(internetModeFromMetadata(undefined)).toBe('auto')
  })

  it('maps explicit booleans to "on"/"off"', () => {
    expect(internetModeFromMetadata({ tool_internet: true })).toBe('on')
    expect(internetModeFromMetadata({ tool_internet: false })).toBe('off')
  })

  it('preserves an explicit false instead of collapsing it to auto', () => {
    expect(internetModeFromMetadata({ tool_internet: false })).not.toBe('auto')
  })

  it('honours the legacy tool_internet_search alias', () => {
    expect(internetModeFromMetadata({ tool_internet_search: true })).toBe('on')
    expect(internetModeFromMetadata({ tool_internet_search: false })).toBe('off')
  })

  it('prefers the canonical key over the legacy alias', () => {
    expect(internetModeFromMetadata({ tool_internet: false, tool_internet_search: true })).toBe(
      'off'
    )
  })
})

describe('applyInternetModeToMetadata', () => {
  it('omits tool_internet for "auto" so the backend keeps the classifier default', () => {
    const metadata: PromptMetadata = {}
    applyInternetModeToMetadata(metadata, 'auto')
    expect('tool_internet' in metadata).toBe(false)
  })

  it('clears a previously set override when switching back to "auto"', () => {
    const metadata: PromptMetadata = { tool_internet: false }
    applyInternetModeToMetadata(metadata, 'auto')
    expect('tool_internet' in metadata).toBe(false)
  })

  it('writes explicit booleans for "on" and "off"', () => {
    const on: PromptMetadata = {}
    applyInternetModeToMetadata(on, 'on')
    expect(on.tool_internet).toBe(true)

    const off: PromptMetadata = {}
    applyInternetModeToMetadata(off, 'off')
    expect(off.tool_internet).toBe(false)
  })

  it('round-trips every mode back to itself', () => {
    for (const mode of ['auto', 'on', 'off'] as const) {
      const metadata: PromptMetadata = {}
      applyInternetModeToMetadata(metadata, mode)
      expect(internetModeFromMetadata(metadata)).toBe(mode)
    }
  })
})
