import { describe, expect, it } from 'vitest'
import { pendingPaymentHold } from '@/router/registrationGate'

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
