import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { openApp } from '../helpers/auth'
import { ChatHelper } from '../helpers/chat'
import { PROMPTS } from '../config/test-data'

/**
 * Phase 4 regression guard: while the assistant message is streaming, the
 * bubble's bounding-box height must only ever grow or stay the same. Any
 * shrink or sub-frame "flash" (height-down-then-up) reads as flicker to
 * the user and indicates a regression in:
 *
 *   - Phase 3a: stable v-for keys on MessagePart
 *   - Phase 3b: in-place reconciliation of message.parts
 *   - Phase 3e: the memory-status pill staying out of the bubble
 *   - Phase 3f: copy button pre-rendered with opacity transition
 *
 * The test polls the bubble's `getBoundingClientRect().height` every 100 ms
 * for the duration of the stream. We tolerate small floating-point noise
 * (<1 px) because subpixel rounding in some browsers can wobble.
 */
test.describe('@noci @nightly Chat bubble flicker guard', () => {
  test('streaming bubble height grows monotonically — no flicker', async ({ page }) => {
    test.setTimeout(60_000)

    await openApp(page)
    const chat = new ChatHelper(page)
    await chat.startNewChat()

    const previousCount = await chat.sendMessage(PROMPTS.CHAT_SMOKE)

    // Bubble for the new assistant response. Index = previousCount in the
    // bubbles list (assistant bubbles only).
    const bubble = chat.conversationBubbles().nth(previousCount)
    await bubble.waitFor({ state: 'attached' })

    // Sample bubble height while the stream runs. We stop sampling either
    // when `data-testid="message-done"` appears (stream complete) or after
    // the safety timeout. 100 ms cadence is fine-grained enough to catch
    // a one-frame flash without being noisy.
    const samples: number[] = []
    const start = Date.now()
    const TIMEOUT_MS = 30_000
    const messageDone = bubble.locator(selectors.chat.messageDone).first()

    while (Date.now() - start < TIMEOUT_MS) {
      const box = await bubble.boundingBox()
      if (box && box.height > 0) {
        samples.push(box.height)
      }
      if ((await messageDone.count()) > 0) {
        break
      }
      await page.waitForTimeout(100)
    }

    expect(samples.length, 'expected at least a few bubble samples').toBeGreaterThan(3)

    // Monotonic non-decreasing check (with sub-pixel tolerance).
    const PIXEL_TOLERANCE = 1
    const violations: Array<{ index: number; prev: number; curr: number }> = []
    for (let i = 1; i < samples.length; i++) {
      const prev = samples[i - 1]
      const curr = samples[i]
      if (prev - curr > PIXEL_TOLERANCE) {
        violations.push({ index: i, prev, curr })
      }
    }

    if (violations.length > 0) {
      console.warn('Bubble height regressions:', violations.slice(0, 5))
    }

    expect(violations, 'streaming bubble height must never shrink (would read as flicker)').toEqual(
      []
    )
  })
})
