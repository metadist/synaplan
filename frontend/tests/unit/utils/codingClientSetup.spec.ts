import { describe, expect, it } from 'vitest'
import {
  codingClientBaseUrl,
  codingClientSetupSnippet,
  providerKeyReady,
} from '@/utils/codingClientSetup'

describe('codingClientBaseUrl', () => {
  it('prefers a non-empty API base URL', () => {
    expect(
      codingClientBaseUrl(
        'http://api.example.test/',
        'http://localhost:8000',
        'http://localhost:5173'
      )
    ).toBe('http://api.example.test')
  })

  it('uses the server hint when the runtime API base is empty', () => {
    expect(codingClientBaseUrl('', 'http://localhost:8000/', 'http://localhost:5173')).toBe(
      'http://localhost:8000'
    )
  })

  it('ignores a hint that is not an origin', () => {
    expect(codingClientBaseUrl('', '(your Synaplan origin)', 'http://localhost:5173')).toBe(
      'http://localhost:5173'
    )
  })
})

describe('codingClientSetupSnippet', () => {
  it('puts the gateway origin in ANTHROPIC_BASE_URL', () => {
    const snippet = codingClientSetupSnippet('http://localhost:8000')
    expect(snippet).toContain('export ANTHROPIC_BASE_URL="http://localhost:8000"')
    expect(snippet).not.toContain('localhost:5173')
  })
})

describe('providerKeyReady', () => {
  it('is ready only for a user or operator key', () => {
    expect(providerKeyReady('user')).toBe(true)
    expect(providerKeyReady('operator')).toBe(true)
    expect(providerKeyReady('none')).toBe(false)
    expect(providerKeyReady(undefined)).toBe(false)
  })
})
