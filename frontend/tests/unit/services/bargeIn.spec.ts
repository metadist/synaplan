import { describe, expect, it } from 'vitest'
import { createBargeInDetector, feedBargeIn, levelDb } from '@/services/voice/bargeIn'

describe('barge-in while a reply is spoken', () => {
  it('ignores the reply leaking into the microphone', () => {
    const detector = createBargeInDetector()
    expect(feedBargeIn(detector, -24, 0.2)).toBe(false)
    expect(feedBargeIn(detector, -28, 0.6)).toBe(false)
    for (let step = 0; step < 30; step += 1) {
      expect(feedBargeIn(detector, -14, 0.9 + step * 0.1)).toBe(false)
    }
    expect(feedBargeIn(detector, -8, 4)).toBe(false)
    expect(feedBargeIn(detector, -8, 4.4)).toBe(true)
  })

  it('ignores a short noise spike', () => {
    const detector = createBargeInDetector()
    expect(feedBargeIn(detector, -60, 0.2)).toBe(false)
    expect(feedBargeIn(detector, -10, 0.9)).toBe(false)
    expect(feedBargeIn(detector, -80, 1)).toBe(false)
    expect(feedBargeIn(detector, -10, 1.1)).toBe(false)
    expect(feedBargeIn(detector, -10, 1.4)).toBe(false)
  })

  it('measures silence and full scale', () => {
    expect(levelDb([])).toBe(-160)
    expect(levelDb([0, 0, 0, 0])).toBe(-160)
    expect(levelDb([1, 1, 1, 1])).toBeCloseTo(0, 3)
  })
})
