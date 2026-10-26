import { describe, expect, it } from 'vitest'
import { signupBlockedText } from '@/utils/signupBlockedMessage'

const t = (key: string) => `t:${key}`

describe('signupBlockedText', () => {
  it('translates a known code', () => {
    expect(signupBlockedText(t, 'SIGNUP_PAUSED', 'Sign-up is paused')).toBe(
      't:auth.signupBlocked.paused'
    )
  })

  it('never shows the raw busy body or transport texts', () => {
    expect(signupBlockedText(t, undefined, 'server_busy')).toBe('t:auth.signupBlocked.busy')
    expect(signupBlockedText(t, undefined, 'Network error')).toBe('t:auth.signupBlocked.network')
    expect(signupBlockedText(t, null, 'Registration failed')).toBe('t:auth.signupBlocked.failed')
  })

  it("keeps the server's own sentence for an unknown answer", () => {
    expect(signupBlockedText(t, 'SOMETHING_NEW', 'A readable sentence.')).toBe(
      'A readable sentence.'
    )
  })
})
