import { describe, it, expect } from 'vitest'
import { isRecoverableStreamError, isCancellationError } from '@/utils/streamError'

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

/**
 * A user-initiated cancellation of a multitask/DAG text node re-surfaces the raw
 * StreamCancelledException message through the generic error path. It must be
 * detected so the chat renders the lightweight translated cancel notice instead
 * of a big `## ⚠️` error heading (and never leaks the English backend text).
 */
describe('isCancellationError', () => {
  it('detects the raw backend cancellation message', () => {
    expect(isCancellationError({ status: 'error', error: 'Stream cancelled by user' })).toBe(true)
  })

  it('detects the message when wrapped by the generic failure prefix', () => {
    expect(
      isCancellationError({
        status: 'error',
        error: 'Failed to process message: Stream cancelled by user',
      })
    ).toBe(true)
  })

  it('reads the cancellation text from the message field too', () => {
    expect(isCancellationError({ status: 'error', message: 'Stream cancelled by user' })).toBe(true)
  })

  it('is false for a non-error status', () => {
    expect(isCancellationError({ status: 'complete', error: 'Stream cancelled by user' })).toBe(
      false
    )
  })

  it('is false for a genuine backend error', () => {
    expect(isCancellationError({ status: 'error', error: 'No model configured' })).toBe(false)
  })
})
