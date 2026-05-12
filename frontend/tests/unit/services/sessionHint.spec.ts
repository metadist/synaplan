import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setSessionHint, clearSessionHint, hasSessionHint } from '@/services/sessionHint'

describe('sessionHint', () => {
  beforeEach(() => {
    localStorage.clear()
    vi.restoreAllMocks()
  })

  it('hasSessionHint returns false on a fresh browser profile', () => {
    expect(hasSessionHint()).toBe(false)
  })

  it('setSessionHint persists the flag and hasSessionHint then returns true', () => {
    setSessionHint()
    expect(hasSessionHint()).toBe(true)
  })

  it('clearSessionHint removes the flag', () => {
    setSessionHint()
    expect(hasSessionHint()).toBe(true)

    clearSessionHint()
    expect(hasSessionHint()).toBe(false)
  })

  it('treats other values stored under the key as "no hint"', () => {
    localStorage.setItem('sh', 'maybe')
    expect(hasSessionHint()).toBe(false)
  })

  it('hasSessionHint falls back to true when localStorage throws', () => {
    // Simulate sandboxed / quota-exceeded localStorage: we MUST NOT lock a
    // working session out just because storage is unavailable.
    const spy = vi.spyOn(globalThis.localStorage, 'getItem').mockImplementation(() => {
      throw new Error('localStorage unavailable')
    })

    expect(hasSessionHint()).toBe(true)

    spy.mockRestore()
  })

  it('setSessionHint swallows storage errors silently', () => {
    const spy = vi.spyOn(globalThis.localStorage, 'setItem').mockImplementation(() => {
      throw new Error('QuotaExceededError')
    })

    expect(() => setSessionHint()).not.toThrow()

    spy.mockRestore()
  })

  it('clearSessionHint swallows storage errors silently', () => {
    const spy = vi.spyOn(globalThis.localStorage, 'removeItem').mockImplementation(() => {
      throw new Error('localStorage unavailable')
    })

    expect(() => clearSessionHint()).not.toThrow()

    spy.mockRestore()
  })
})
