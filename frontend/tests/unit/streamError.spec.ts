import { describe, it, expect } from 'vitest'
import { isRecoverableStreamError } from '@/utils/streamError'

/**
 * Issue #1265: a pure SSE transport drop must be recoverable (reconcile with the
 * persisted state) rather than shown as a permanent error bubble that vanishes
 * on refresh. Genuine backend errors must keep surfacing.
 */
describe('isRecoverableStreamError (issue #1265)', () => {
  it('treats a bare connection drop as recoverable', () => {
    expect(isRecoverableStreamError({ status: 'error', error: 'Connection interrupted' })).toBe(
      true
    )
    expect(isRecoverableStreamError({ status: 'error', error: 'Failed to connect' })).toBe(true)
  })

  it('is false for a non-error status', () => {
    expect(isRecoverableStreamError({ status: 'complete', error: 'Connection interrupted' })).toBe(
      false
    )
  })

  it('is false for a genuine backend error message', () => {
    expect(isRecoverableStreamError({ status: 'error', error: 'No model configured' })).toBe(false)
    expect(isRecoverableStreamError({ status: 'error', message: 'Provider unavailable' })).toBe(
      false
    )
  })

  it('is false when a structured backend signal is present, even with a drop message', () => {
    // Defensive: a real error must never be misclassified as a transport drop.
    expect(
      isRecoverableStreamError({
        status: 'error',
        error: 'Connection interrupted',
        code: 'COST_BUDGET_EXCEEDED',
      })
    ).toBe(false)
    expect(
      isRecoverableStreamError({
        status: 'error',
        error: 'Connection interrupted',
        limit_type: 'monthly',
      })
    ).toBe(false)
    expect(
      isRecoverableStreamError({
        status: 'error',
        error: 'Connection interrupted',
        install_command: 'ollama pull llama3',
      })
    ).toBe(false)
  })
})
