import { afterEach, describe, expect, it, vi } from 'vitest'

import { createUuid } from '@/utils/uuid'

const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

describe('createUuid', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('uses crypto.randomUUID when the page is a secure context', () => {
    const spy = vi
      .spyOn(crypto, 'randomUUID')
      .mockReturnValue('11111111-2222-4333-8444-555555555555')

    expect(createUuid()).toBe('11111111-2222-4333-8444-555555555555')
    expect(spy).toHaveBeenCalledOnce()
  })

  it('builds a version 4 UUID when randomUUID is missing (plain http on a LAN address)', () => {
    const original = crypto.randomUUID
    Object.defineProperty(crypto, 'randomUUID', { value: undefined, configurable: true })

    try {
      const ids = new Set(Array.from({ length: 200 }, () => createUuid()))
      expect(ids.size).toBe(200)
      for (const id of ids) {
        expect(id).toMatch(UUID_V4)
      }
    } finally {
      Object.defineProperty(crypto, 'randomUUID', { value: original, configurable: true })
    }
  })
})
