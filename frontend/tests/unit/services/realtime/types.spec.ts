import { describe, expect, it } from 'vitest'
import { RealtimeEnvelopeSchema } from '@/services/realtime/types'

describe('RealtimeEnvelopeSchema', () => {
  it('accepts a canonical envelope', () => {
    const parsed = RealtimeEnvelopeSchema.parse({
      type: 'message.received',
      ts: 1700000000000,
      data: { messageId: 'm_1', text: 'hello' },
    })

    expect(parsed.type).toBe('message.received')
    expect(parsed.ts).toBe(1700000000000)
    expect(parsed.data).toEqual({ messageId: 'm_1', text: 'hello' })
  })

  it('rejects an envelope with the wrong shape', () => {
    expect(() => RealtimeEnvelopeSchema.parse({ type: 1, ts: 'now', data: 'oops' })).toThrow()
  })

  it('rejects a payload that is not an object', () => {
    expect(() => RealtimeEnvelopeSchema.parse({ type: 'evt', ts: 1, data: ['array'] })).toThrow()
  })
})
