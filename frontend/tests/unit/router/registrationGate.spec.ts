import { describe, expect, it } from 'vitest'
import { resolveRegistrationRedirect } from '@/router/registrationGate'

describe('resolveRegistrationRedirect', () => {
  it('leaves /register open while self-registration is on', () => {
    expect(resolveRegistrationRedirect(true)).toBeNull()
  })

  it('sends /register to login when self-registration is off', () => {
    expect(resolveRegistrationRedirect(false)).toEqual({ name: 'login', query: {} })
  })

  it('keeps a deep-link query so the visitor is not dropped on a blank login', () => {
    expect(resolveRegistrationRedirect(false, { redirect: '/files' })).toEqual({
      name: 'login',
      query: { redirect: '/files' },
    })
  })
})
