/**
 * Tests for `useKeyboardOpen` — the small composable that drives the iOS
 * input-bar gap fix on chat. The composable must:
 *
 *   1. Stay `false` when `window.visualViewport` is missing (jsdom, old
 *      Safari, some WebViews) so the consumer keeps the safe iPhone-home
 *      indicator inset.
 *   2. Flip `true` only when the visual viewport shrinks by more than the
 *      150px MIN_DELTA_PX threshold — URL-bar collapses (typically ≤100px)
 *      must NOT trigger it.
 *   3. Update on both `resize` AND `scroll` of `visualViewport` (iOS fires
 *      both during keyboard show/hide).
 *   4. Tear down its listeners on unmount.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h, nextTick } from 'vue'
import { useKeyboardOpen } from '@/composables/useKeyboardOpen'

type FakeViewport = {
  height: number
  scale: number
  addEventListener: (event: string, handler: () => void) => void
  removeEventListener: (event: string, handler: () => void) => void
}

let listeners: Map<string, Set<() => void>>
let originalVisualViewport: VisualViewport | null | undefined
let originalInnerHeight: number

const installFakeViewport = (height: number, scale: number = 1): FakeViewport => {
  listeners = new Map()
  const fake: FakeViewport = {
    height,
    scale,
    addEventListener: (event, handler) => {
      if (!listeners.has(event)) listeners.set(event, new Set())
      listeners.get(event)!.add(handler)
    },
    removeEventListener: (event, handler) => {
      listeners.get(event)?.delete(handler)
    },
  }
  Object.defineProperty(window, 'visualViewport', {
    value: fake,
    configurable: true,
    writable: true,
  })
  return fake
}

const fireEvent = (event: string): void => {
  for (const handler of listeners.get(event) ?? []) handler()
}

const Probe = defineComponent({
  setup() {
    const isOpen = useKeyboardOpen()
    return () => h('span', { 'data-testid': 'probe' }, String(isOpen.value))
  },
})

describe('useKeyboardOpen', () => {
  beforeEach(() => {
    originalVisualViewport = window.visualViewport
    originalInnerHeight = window.innerHeight
    Object.defineProperty(window, 'innerHeight', {
      value: 800,
      configurable: true,
      writable: true,
    })
  })

  afterEach(() => {
    Object.defineProperty(window, 'visualViewport', {
      value: originalVisualViewport,
      configurable: true,
      writable: true,
    })
    Object.defineProperty(window, 'innerHeight', {
      value: originalInnerHeight,
      configurable: true,
      writable: true,
    })
    vi.restoreAllMocks()
  })

  it('returns false when visualViewport is unavailable (jsdom / old browsers)', () => {
    Object.defineProperty(window, 'visualViewport', {
      value: undefined,
      configurable: true,
      writable: true,
    })

    const wrapper = mount(Probe)
    expect(wrapper.text()).toBe('false')
  })

  it('reports keyboard-open when the visualViewport shrinks past the threshold', async () => {
    const fake = installFakeViewport(800)

    const wrapper = mount(Probe)
    expect(wrapper.text()).toBe('false')

    // Keyboard typically eats ≥ 250 px on iOS / Android. Simulate that.
    fake.height = 540
    fireEvent('resize')
    await nextTick()
    expect(wrapper.text()).toBe('true')

    // Keyboard hidden again -> back to flat.
    fake.height = 800
    fireEvent('resize')
    await nextTick()
    expect(wrapper.text()).toBe('false')
  })

  it('does NOT trigger on shallow URL-bar collapse (< 150px delta)', async () => {
    const fake = installFakeViewport(800)
    const wrapper = mount(Probe)

    // iOS Safari URL bar collapse is ~60-100px; never as deep as a keyboard.
    fake.height = 720 // 80px delta
    fireEvent('resize')
    await nextTick()
    expect(wrapper.text()).toBe('false')

    fake.height = 705 // 95px delta — still URL-bar territory
    fireEvent('resize')
    await nextTick()
    expect(wrapper.text()).toBe('false')
  })

  it('also reacts to visualViewport scroll events (iOS fires both)', async () => {
    const fake = installFakeViewport(800)
    const wrapper = mount(Probe)

    fake.height = 500
    fireEvent('scroll')
    await nextTick()
    expect(wrapper.text()).toBe('true')
  })

  it('cleans up its visualViewport listeners on unmount', () => {
    installFakeViewport(800)
    const wrapper = mount(Probe)

    expect(listeners.get('resize')?.size).toBe(1)
    expect(listeners.get('scroll')?.size).toBe(1)

    wrapper.unmount()

    expect(listeners.get('resize')?.size).toBe(0)
    expect(listeners.get('scroll')?.size).toBe(0)
  })

  // Per Copilot review on PR #907: pinch-zoom shrinks
  // `visualViewport.height` proportionally to `visualViewport.scale`, which
  // pre-fix would falsely trigger keyboard-open and rip the safe-area inset
  // out from under a user who's just zooming in to inspect a chart / table.
  // Guard: only attribute the height delta to the keyboard when scale ≈ 1.
  it('does NOT trigger keyboard-open during pinch-zoom (scale > 1)', async () => {
    // Pinch to 2× zoom: visualViewport.height roughly halves while scale
    // doubles. Without the scale guard, the delta would be ≥ 150 px and
    // the composable would erroneously flip true.
    const fake = installFakeViewport(400, 2)
    const wrapper = mount(Probe)
    expect(wrapper.text()).toBe('false')

    // Even firing a resize while still zoomed must keep it false.
    fake.height = 350
    fireEvent('resize')
    await nextTick()
    expect(wrapper.text()).toBe('false')

    // Once the user pinches back to scale = 1 *and* the keyboard happens
    // to be up at that moment, the composable must flip true again.
    fake.scale = 1
    fake.height = 540
    fireEvent('resize')
    await nextTick()
    expect(wrapper.text()).toBe('true')
  })

  it('tolerates near-1 scale values (subpixel rounding) without flipping false', async () => {
    // Android Chrome occasionally reports scale = 1.001 / 0.999 during a
    // resize burst that is otherwise a plain keyboard show. Stay within
    // the documented ±0.05 tolerance so we don't regress the keyboard-up
    // detection on those devices.
    const fake = installFakeViewport(800, 1)
    const wrapper = mount(Probe)

    fake.scale = 1.02
    fake.height = 500
    fireEvent('resize')
    await nextTick()
    expect(wrapper.text()).toBe('true')

    fake.scale = 0.98
    fireEvent('resize')
    await nextTick()
    expect(wrapper.text()).toBe('true')
  })
})
