import { describe, expect, it } from 'vitest'
import { pendingPaymentHold, resolveRegistrationRedirect } from '@/router/registrationGate'

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

describe('pendingPaymentHold', () => {
  it('sends a pending account to the plan page', () => {
    expect(pendingPaymentHold('pending_payment', 'chat')).toBe('pay')
  })

  it('leaves the plan page and both checkout return routes open', () => {
    expect(pendingPaymentHold('pending_payment', 'subscription')).toBeNull()
    expect(pendingPaymentHold('pending_payment', 'subscription-success')).toBeNull()
    expect(pendingPaymentHold('pending_payment', 'subscription-cancel')).toBeNull()
    expect(pendingPaymentHold('pending_payment', 'login')).toBeNull()
  })

  it('does nothing for an active account', () => {
    expect(pendingPaymentHold('active', 'chat')).toBeNull()
    expect(pendingPaymentHold(undefined, 'chat')).toBeNull()
  })
})
