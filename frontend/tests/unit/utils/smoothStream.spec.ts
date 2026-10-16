import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { CATCH_UP_MS, createSmoothStream } from '@/utils/smoothStream'

let now = 0
let nextFrameId = 1
let pending: Map<number, FrameRequestCallback>
let reducedMotion = false
let hidden = false

beforeEach(() => {
  now = 0
  nextFrameId = 1
  pending = new Map()
  reducedMotion = false
  hidden = false

  vi.stubGlobal('performance', { now: () => now })
  vi.stubGlobal('requestAnimationFrame', (cb: FrameRequestCallback) => {
    const id = nextFrameId++
    pending.set(id, cb)
    return id
  })
  vi.stubGlobal('cancelAnimationFrame', (id: number) => {
    pending.delete(id)
  })
  vi.stubGlobal('matchMedia', (query: string) => ({
    matches: reducedMotion && query.includes('prefers-reduced-motion'),
    addEventListener: () => {},
    removeEventListener: () => {},
  }))
  Object.defineProperty(document, 'hidden', {
    configurable: true,
    get: () => hidden,
  })
})

afterEach(() => {
  vi.unstubAllGlobals()
  Reflect.deleteProperty(document, 'hidden')
})

function pump(dt = 16): void {
  now += dt
  const frames = [...pending.values()]
  pending.clear()
  for (const frame of frames) frame(now)
}

describe('createSmoothStream', () => {
  it('reveals a short reply a little at a time', () => {
    const paints: string[] = []
    const smoother = createSmoothStream({ onRender: (text) => paints.push(text) })

    smoother.push('Hello there')
    pump()
    pump()

    expect(paints.length).toBeGreaterThan(0)
    expect(paints[0]!.length).toBeGreaterThan(0)
    expect(paints[0]!.length).toBeLessThan('Hello there'.length)
    expect(paints[1]!.startsWith(paints[0]!)).toBe(true)
    expect(paints[1]!.length).toBeGreaterThan(paints[0]!.length)
  })

  it('catches a large backlog up within the catch-up window', () => {
    const paints: string[] = []
    const smoother = createSmoothStream({ onRender: (text) => paints.push(text) })
    const text = 'a'.repeat(3000)

    smoother.push(text)
    const frames = Math.ceil(CATCH_UP_MS / 16)
    for (let i = 0; i < frames; i += 1) pump(16)

    expect(paints[paints.length - 1]).toBe(text)
    expect(now).toBeLessThanOrEqual(CATCH_UP_MS + 16)
  })

  it('flush paints the rest immediately and cancel paints nothing more', () => {
    const paints: string[] = []
    const streaming = createSmoothStream({ onRender: (text) => paints.push(text) })
    streaming.push('abcdefghijklmnopqrstuvwxyz')
    streaming.flush()
    expect(paints).toEqual(['abcdefghijklmnopqrstuvwxyz'])
    expect(pending.size).toBe(0)

    const stopped = createSmoothStream({ onRender: (text) => paints.push(text) })
    paints.length = 0
    stopped.push('abcdefghijklmnopqrstuvwxyz')
    stopped.cancel()
    pump()
    pump()
    expect(paints).toEqual([])
    stopped.flush()
    stopped.push('more')
    expect(paints).toEqual([])
  })

  it('paints immediately when the user prefers reduced motion', () => {
    reducedMotion = true
    const paints: string[] = []
    const smoother = createSmoothStream({ onRender: (text) => paints.push(text) })

    smoother.push('Hello there')

    expect(paints).toEqual(['Hello there'])
    expect(pending.size).toBe(0)
  })

  it('paints immediately while the tab is in the background', () => {
    hidden = true
    const paints: string[] = []
    const smoother = createSmoothStream({ onRender: (text) => paints.push(text) })

    smoother.push('Hello there')

    expect(paints).toEqual(['Hello there'])
  })

  it('flushes outstanding text when the tab is hidden mid-stream', () => {
    const paints: string[] = []
    const smoother = createSmoothStream({ onRender: (text) => paints.push(text) })
    smoother.push('Hello there, this is a longer reply')

    hidden = true
    document.dispatchEvent(new Event('visibilitychange'))

    expect(paints[paints.length - 1]).toBe('Hello there, this is a longer reply')
  })

  it('does not split an emoji or flash a partial think tag', () => {
    const paints: string[] = []
    const smoother = createSmoothStream({ onRender: (text) => paints.push(text) })

    smoother.push('A👍B')
    pump()
    for (const paint of paints) {
      expect(paint.includes('\uD83D') && !paint.includes('👍')).toBe(false)
    }
    smoother.cancel()

    paints.length = 0
    const tagged = createSmoothStream({ onRender: (text) => paints.push(text) })
    tagged.push('<th')
    pump()
    expect(paints).toEqual([])

    tagged.push('<think>hi</think>')
    pump()
    for (const paint of paints) {
      expect(paint.startsWith('<th') && !paint.startsWith('<think>')).toBe(false)
      expect(paint.includes('</th') && !paint.includes('</think>')).toBe(false)
    }
    expect(paints[0]?.startsWith('<think>')).toBe(true)
  })

  it('never rewinds text that was already shown', () => {
    const paints: string[] = []
    const smoother = createSmoothStream({
      onRender: (text) => paints.push(text),
      initialShown: 'Hello there',
    })

    smoother.push('Hel')
    pump()
    expect(paints).toEqual([])

    smoother.push('Hello there, friend')
    pump()
    expect(paints[0]?.startsWith('Hello there')).toBe(true)
  })
})
