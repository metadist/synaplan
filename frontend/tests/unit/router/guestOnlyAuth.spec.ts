import { describe, expect, it } from 'vitest'
import { isGuestOnlyAuthRoute } from '@/router/guestOnlyAuth'

describe('isGuestOnlyAuthRoute', () => {
  it('treats login and register as signed-out-only', () => {
    expect(isGuestOnlyAuthRoute('login')).toBe(true)
    expect(isGuestOnlyAuthRoute('register')).toBe(true)
  })

  it('leaves the rest of the app alone, including chat', () => {
    expect(isGuestOnlyAuthRoute('chat')).toBe(false)
    expect(isGuestOnlyAuthRoute('forgot-password')).toBe(false)
    expect(isGuestOnlyAuthRoute(undefined)).toBe(false)
    expect(isGuestOnlyAuthRoute(Symbol('login'))).toBe(false)
  })
})
