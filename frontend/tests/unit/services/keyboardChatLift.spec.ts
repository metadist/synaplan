import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import {
  chatKeyboardLiftPx,
  initChatKeyboardLift,
  type ChatKeyboardLift,
} from '@/services/keyboardChatLift'

const FULL_HEIGHT = 800
const KEYBOARD = 300

describe('chatKeyboardLiftPx', () => {
  const base = { keyboardPx: KEYBOARD, baselineHeight: FULL_HEIGHT, layoutHeight: FULL_HEIGHT }

  it('lifts by the full overlap while the WebView keeps its height (iOS, overlay Android)', () => {
    expect(chatKeyboardLiftPx({ ...base, panPx: 0 })).toBe(KEYBOARD)
  })

  it('does not lift when the WebView already shrank above the keyboard', () => {
    expect(chatKeyboardLiftPx({ ...base, layoutHeight: FULL_HEIGHT - KEYBOARD, panPx: 0 })).toBe(0)
  })

  it('treats a drop of a few pixels as noise, not a resize', () => {
    expect(chatKeyboardLiftPx({ ...base, layoutHeight: FULL_HEIGHT - 8, panPx: 0 })).toBe(KEYBOARD)
  })

  it('subtracts a visual viewport pan that already moved the page up', () => {
    expect(chatKeyboardLiftPx({ ...base, panPx: 120 })).toBe(KEYBOARD - 120)
    expect(chatKeyboardLiftPx({ ...base, panPx: KEYBOARD + 50 })).toBe(0)
  })

  it('is 0 while the keyboard is closed', () => {
    expect(chatKeyboardLiftPx({ ...base, keyboardPx: 0, panPx: 0 })).toBe(0)
  })
})

describe('initChatKeyboardLift', () => {
  const root = document.documentElement
  let lift: ChatKeyboardLift | null = null

  const setLayoutHeight = (height: number): void => {
    Object.defineProperty(window, 'innerHeight', { configurable: true, value: height })
    window.dispatchEvent(new Event('resize'))
  }
  // The shell writes the overlap straight onto <html>; MutationObserver
  // callbacks run as a microtask.
  const shellWritesOverlap = async (px: number): Promise<void> => {
    root.style.setProperty('--keyboard-inset-height', `${px}px`)
    await Promise.resolve()
  }
  const publishedLift = (): string => root.style.getPropertyValue('--chat-keyboard-lift')

  beforeEach(() => {
    root.removeAttribute('style')
    root.className = ''
    setLayoutHeight(FULL_HEIGHT)
  })

  afterEach(() => {
    lift?.stop()
    lift = null
  })

  it('follows the overlap on iOS, where the WebView never resizes', async () => {
    lift = initChatKeyboardLift()
    lift.rememberLayoutHeight()
    root.classList.add('synaplan-keyboard-open')

    await shellWritesOverlap(120)
    expect(publishedLift()).toBe('120px')
    await shellWritesOverlap(KEYBOARD)
    expect(publishedLift()).toBe(`${KEYBOARD}px`)
  })

  it('drops the lift once Android shrinks the WebView above the keyboard', async () => {
    lift = initChatKeyboardLift()
    lift.rememberLayoutHeight()
    root.classList.add('synaplan-keyboard-open')

    await shellWritesOverlap(KEYBOARD)
    expect(publishedLift()).toBe(`${KEYBOARD}px`)

    setLayoutHeight(FULL_HEIGHT - KEYBOARD)
    expect(publishedLift()).toBe('0px')

    // Later frames carry the full IME height again; the page must stay put.
    await shellWritesOverlap(KEYBOARD)
    expect(publishedLift()).toBe('0px')
  })

  it('keeps the closed height when the WebView shrinks before the first frame', async () => {
    lift = initChatKeyboardLift()
    lift.rememberLayoutHeight()
    root.classList.add('synaplan-keyboard-open')

    setLayoutHeight(FULL_HEIGHT - KEYBOARD)
    // Docking again while the keyboard is up must not adopt the shrunk height.
    lift.rememberLayoutHeight()
    await shellWritesOverlap(KEYBOARD)
    expect(publishedLift()).toBe('0px')
  })

  it('lifts again after the keyboard closed and the next one overlays', async () => {
    lift = initChatKeyboardLift()
    lift.rememberLayoutHeight()
    root.classList.add('synaplan-keyboard-open')
    setLayoutHeight(FULL_HEIGHT - KEYBOARD)
    await shellWritesOverlap(KEYBOARD)
    expect(publishedLift()).toBe('0px')

    root.classList.remove('synaplan-keyboard-open')
    await shellWritesOverlap(0)
    setLayoutHeight(FULL_HEIGHT)
    window.dispatchEvent(
      new CustomEvent('synaplan:keyboardinset', { detail: { height: 0, keyboardHeight: 0 } })
    )
    expect(publishedLift()).toBe('0px')

    lift.rememberLayoutHeight()
    root.classList.add('synaplan-keyboard-open')
    await shellWritesOverlap(KEYBOARD)
    expect(publishedLift()).toBe(`${KEYBOARD}px`)
  })
})
