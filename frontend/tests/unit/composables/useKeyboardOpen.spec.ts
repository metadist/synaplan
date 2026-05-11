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
  addEventListener: (event: string, handler: () => void) => void
  removeEventListener: (event: string, handler: () => void) => void
}

let listeners: Map<string, Set<() => void>>
let originalVisualViewport: VisualViewport | null | undefined
let originalInnerHeight: number

const installFakeViewport = (height: number): FakeViewport => {
  listeners = new Map()
  const fake: FakeViewport = {
    height,
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
})
