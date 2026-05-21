import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  clearPendingRedirect,
  consumePendingRedirect,
  isSafeRedirectPath,
  peekPendingRedirect,
  setPendingRedirect,
} from '@/utils/pendingAuthRedirect'

const KEY = 'synaplan.pendingAuthRedirect'

beforeEach(() => {
  sessionStorage.clear()
  vi.useRealTimers()
})

afterEach(() => {
  sessionStorage.clear()
})

describe('isSafeRedirectPath', () => {
  it('accepts a single-leading-slash same-origin path', () => {
    expect(isSafeRedirectPath('/addin/connect?state=abc')).toBe(true)
    expect(isSafeRedirectPath('/chat')).toBe(true)
    expect(isSafeRedirectPath('/')).toBe(true)
  })

  it('rejects non-strings and empty strings', () => {
    expect(isSafeRedirectPath(undefined)).toBe(false)
    expect(isSafeRedirectPath(null)).toBe(false)
    expect(isSafeRedirectPath(123)).toBe(false)
    expect(isSafeRedirectPath({})).toBe(false)
    expect(isSafeRedirectPath('')).toBe(false)
  })

  it('rejects protocol-relative URLs (open-redirect vector)', () => {
    expect(isSafeRedirectPath('//evil.example/path')).toBe(false)
    expect(isSafeRedirectPath('//evil.example')).toBe(false)
  })

  it('rejects schemed URLs', () => {
    expect(isSafeRedirectPath('https://evil.example/path')).toBe(false)
    expect(isSafeRedirectPath('http://localhost/path')).toBe(false)
    expect(isSafeRedirectPath('javascript:alert(1)')).toBe(false)
    expect(isSafeRedirectPath('data:text/html,<script>')).toBe(false)
  })

  it('rejects paths that do not start with /', () => {
    expect(isSafeRedirectPath('addin/connect')).toBe(false)
    expect(isSafeRedirectPath('?state=abc')).toBe(false)
  })

  it('rejects payloads over 2 KiB', () => {
    expect(isSafeRedirectPath('/' + 'a'.repeat(2047))).toBe(true)
    expect(isSafeRedirectPath('/' + 'a'.repeat(2048))).toBe(false)
  })
})

describe('setPendingRedirect + peekPendingRedirect', () => {
  it('persists a safe path and reads it back without clearing', () => {
    setPendingRedirect('/addin/connect?state=NONCE&baseUrl=https%3A%2F%2Fweb.synaplan.com')
    expect(peekPendingRedirect()).toBe(
      '/addin/connect?state=NONCE&baseUrl=https%3A%2F%2Fweb.synaplan.com'
    )
    // Peek must not clear.
    expect(peekPendingRedirect()).toBe(
      '/addin/connect?state=NONCE&baseUrl=https%3A%2F%2Fweb.synaplan.com'
    )
  })

  it('silently ignores unsafe inputs without throwing', () => {
    setPendingRedirect('https://evil.example/path')
    setPendingRedirect('//evil.example')
    setPendingRedirect('not-a-path')
    setPendingRedirect('')
    expect(peekPendingRedirect()).toBeNull()
    expect(sessionStorage.getItem(KEY)).toBeNull()
  })

  it('overwrites an earlier value', () => {
    setPendingRedirect('/first')
    setPendingRedirect('/second')
    expect(peekPendingRedirect()).toBe('/second')
  })
})

describe('consumePendingRedirect', () => {
  it('returns the stored path and clears the entry', () => {
    setPendingRedirect('/addin/connect?state=NONCE')
    expect(consumePendingRedirect()).toBe('/addin/connect?state=NONCE')
    expect(peekPendingRedirect()).toBeNull()
    expect(sessionStorage.getItem(KEY)).toBeNull()
  })

  it('returns null when nothing is stored', () => {
    expect(consumePendingRedirect()).toBeNull()
  })

  it('is one-shot — a second consume yields null', () => {
    setPendingRedirect('/chat')
    expect(consumePendingRedirect()).toBe('/chat')
    expect(consumePendingRedirect()).toBeNull()
  })
})

describe('TTL behaviour', () => {
  it('expires an entry older than 10 minutes and clears it on access', () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-05-21T10:00:00Z'))
    setPendingRedirect('/addin/connect?state=NONCE')

    vi.setSystemTime(new Date('2026-05-21T10:09:59Z'))
    expect(peekPendingRedirect()).toBe('/addin/connect?state=NONCE')

    vi.setSystemTime(new Date('2026-05-21T10:10:01Z'))
    expect(peekPendingRedirect()).toBeNull()
    expect(sessionStorage.getItem(KEY)).toBeNull()
  })
})

describe('clearPendingRedirect', () => {
  it('removes any stored entry', () => {
    setPendingRedirect('/chat')
    clearPendingRedirect()
    expect(peekPendingRedirect()).toBeNull()
    expect(sessionStorage.getItem(KEY)).toBeNull()
  })

  it('is a no-op when nothing is stored', () => {
    expect(() => clearPendingRedirect()).not.toThrow()
  })
})

describe('robustness against corrupted storage', () => {
  it('self-heals entries with malformed JSON', () => {
    sessionStorage.setItem(KEY, '{not json')
    expect(peekPendingRedirect()).toBeNull()
    // The malformed entry must be removed so subsequent reads don't keep
    // re-parsing the same garbage.
    expect(sessionStorage.getItem(KEY)).toBeNull()
  })

  it('self-heals entries with the wrong shape', () => {
    sessionStorage.setItem(KEY, JSON.stringify({ path: 123, expiresAt: 'soon' }))
    expect(peekPendingRedirect()).toBeNull()
    expect(sessionStorage.getItem(KEY)).toBeNull()
  })

  it('self-heals entries whose stored path fails re-validation on read', () => {
    // Mimic a tampered or stale entry: correct JSON shape and future
    // expiry, but the path itself is unsafe (protocol-relative). Even
    // though setPendingRedirect would have rejected this at write time,
    // the helper must defend against the value already being present.
    sessionStorage.setItem(
      KEY,
      JSON.stringify({ path: '//evil.example/path', expiresAt: Date.now() + 60_000 })
    )
    expect(peekPendingRedirect()).toBeNull()
    expect(sessionStorage.getItem(KEY)).toBeNull()
  })

  it('also self-heals entries with a schemed path', () => {
    sessionStorage.setItem(
      KEY,
      JSON.stringify({ path: 'javascript:alert(1)', expiresAt: Date.now() + 60_000 })
    )
    expect(consumePendingRedirect()).toBeNull()
    expect(sessionStorage.getItem(KEY)).toBeNull()
  })

  it('tolerates sessionStorage throwing on get', () => {
    const spy = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('quota')
    })
    expect(peekPendingRedirect()).toBeNull()
    expect(consumePendingRedirect()).toBeNull()
    spy.mockRestore()
  })

  it('tolerates sessionStorage throwing on set', () => {
    const spy = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('quota')
    })
    expect(() => setPendingRedirect('/chat')).not.toThrow()
    spy.mockRestore()
  })
})
