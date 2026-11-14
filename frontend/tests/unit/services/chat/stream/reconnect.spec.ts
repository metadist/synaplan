import { describe, expect, it } from 'vitest'
import { reconnectDelayMs, resumeFrom } from '@/services/chat/stream/reconnect'

describe('stream reconnect', () => {
  it('waits longer on each of the three tries', () => {
    expect(reconnectDelayMs(0)).toBe(300)
    expect(reconnectDelayMs(1)).toBe(800)
    expect(reconnectDelayMs(2)).toBe(1600)
  })

  it('rebuilds from the start when the stream had no event id', () => {
    expect(resumeFrom(false, 0)).toEqual({ from: 0, rebuild: true })
    expect(resumeFrom(true, 0)).toEqual({ from: 0, rebuild: true })
  })

  it('appends after the last id when the stream numbered its frames', () => {
    expect(resumeFrom(true, 12)).toEqual({ from: 12, rebuild: false })
  })
})
