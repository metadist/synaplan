import { describe, expect, it } from 'vitest'
import { canComposeChat, isSharedConversationLocked } from '@/utils/sharedConversationLock'

describe('isSharedConversationLocked', () => {
  it('locks a shared conversation the member can only read or use', () => {
    expect(isSharedConversationLocked('read', false)).toBe(true)
    expect(isSharedConversationLocked('use', false)).toBe(true)
  })

  it('does not lock the owner or an unresolved lookup', () => {
    expect(isSharedConversationLocked('owner', false)).toBe(false)
    expect(isSharedConversationLocked(null, false)).toBe(false)
  })

  it('unlocks while an incognito session is the surface', () => {
    expect(isSharedConversationLocked('read', true)).toBe(false)
    expect(isSharedConversationLocked('use', true)).toBe(false)
  })
})

describe('canComposeChat', () => {
  it('hides the composer on a shared conversation when sharing is on', () => {
    expect(
      canComposeChat({ guest: false, incognito: false, access: 'use', sharingEnabled: true })
    ).toBe(false)
    expect(
      canComposeChat({ guest: false, incognito: false, access: 'read', sharingEnabled: true })
    ).toBe(false)
  })

  it('shows the composer for an incognito session started from a shared conversation', () => {
    expect(
      canComposeChat({ guest: false, incognito: true, access: 'use', sharingEnabled: true })
    ).toBe(true)
    expect(
      canComposeChat({ guest: false, incognito: true, access: 'read', sharingEnabled: true })
    ).toBe(true)
  })

  it('keeps the owner and guest composers', () => {
    expect(
      canComposeChat({ guest: false, incognito: false, access: 'owner', sharingEnabled: true })
    ).toBe(true)
    expect(
      canComposeChat({ guest: true, incognito: false, access: 'read', sharingEnabled: true })
    ).toBe(true)
  })
})
