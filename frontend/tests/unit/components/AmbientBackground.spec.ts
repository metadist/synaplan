import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

const { mockRoute } = vi.hoisted(() => ({
  mockRoute: { path: '/chat' },
}))

vi.mock('vue-router', () => ({
  useRoute: () => mockRoute,
}))

import AmbientBackground from '@/components/AmbientBackground.vue'
import { randomizeAmbientPlacement } from '@/utils/ambientPlacement'

function parsePct(value: string): number {
  return parseFloat(value.replace('%', ''))
}

function parseDeg(style: string): number {
  return parseFloat(/rotate\(([\d.-]+)deg\)/.exec(style)?.[1] ?? 'NaN')
}

describe('randomizeAmbientPlacement', () => {
  it('pins the corners with a seeded rng', () => {
    expect(randomizeAmbientPlacement(() => 0)).toEqual({ topPct: 8, rightPct: 5, rotateDeg: 12 })
    expect(randomizeAmbientPlacement(() => 1)).toEqual({ topPct: 28, rightPct: 25, rotateDeg: -3 })
  })

  it('stays inside the drift bands', () => {
    for (let i = 0; i < 50; i++) {
      const p = randomizeAmbientPlacement()
      expect(p.topPct).toBeGreaterThanOrEqual(8)
      expect(p.topPct).toBeLessThanOrEqual(28)
      expect(p.rightPct).toBeGreaterThanOrEqual(5)
      expect(p.rightPct).toBeLessThanOrEqual(25)
      expect(p.rotateDeg).toBeGreaterThanOrEqual(-3)
      expect(p.rotateDeg).toBeLessThanOrEqual(12)
    }
  })
})

describe('AmbientBackground', () => {
  const webdriverDescriptor = Object.getOwnPropertyDescriptor(window.navigator, 'webdriver')

  beforeEach(() => {
    vi.clearAllMocks()
    // happy-dom reports webdriver=true; a real browser reports false, so pin
    // that for every test except the E2E guard below.
    Object.defineProperty(window.navigator, 'webdriver', { value: false, configurable: true })
  })

  afterEach(() => {
    if (webdriverDescriptor) {
      Object.defineProperty(window.navigator, 'webdriver', webdriverDescriptor)
    } else {
      delete (window.navigator as unknown as { webdriver?: unknown }).webdriver
    }
  })

  it('renders the bird inside its drift bands with the login watermark opacity', () => {
    const wrapper = mount(AmbientBackground)
    const bird = wrapper.get('[data-testid="ambient-bird"]')
    const style = bird.attributes('style') ?? ''

    const top = parsePct(/top:\s*([\d.]+%)/.exec(style)?.[1] ?? 'NaN')
    const right = parsePct(/right:\s*([\d.]+%)/.exec(style)?.[1] ?? 'NaN')
    const rotate = parseDeg(style)

    expect(top).toBeGreaterThanOrEqual(8)
    expect(top).toBeLessThanOrEqual(28)
    expect(right).toBeGreaterThanOrEqual(5)
    expect(right).toBeLessThanOrEqual(25)
    expect(rotate).toBeGreaterThanOrEqual(-3)
    expect(rotate).toBeLessThanOrEqual(12)
    expect(bird.classes()).toContain('opacity-[0.035]')
    expect(bird.classes()).toContain('dark:opacity-[0.06]')
    expect(bird.attributes('aria-hidden')).toBeUndefined()
    expect(wrapper.get('[data-testid="ambient-background"]').attributes('aria-hidden')).toBe('true')
  })

  it('renders the wandering spotlight layer', () => {
    const wrapper = mount(AmbientBackground)
    expect(wrapper.find('[data-testid="ambient-spotlight"]').exists()).toBe(true)
  })

  it('stays out of the way of clicks', () => {
    const wrapper = mount(AmbientBackground)
    expect(wrapper.get('[data-testid="ambient-background"]').classes()).toContain(
      'pointer-events-none'
    )
    expect(wrapper.get('[data-testid="ambient-bird"]').classes()).toContain('pointer-events-none')
  })

  it('hides while E2E drives so visual snapshots stay deterministic', () => {
    Object.defineProperty(window.navigator, 'webdriver', { value: true, configurable: true })
    const wrapper = mount(AmbientBackground)
    expect(wrapper.find('[data-testid="ambient-background"]').exists()).toBe(false)
  })
})
