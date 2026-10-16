import type { Locator } from '@playwright/test'
import { selectors } from './selectors'

/**
 * Resolve when an assistant turn reached its SUCCESS terminal state, throw
 * when it reached its ERROR terminal state.
 *
 * Both states must be raced. Waiting on `message-done` alone cannot observe a
 * failed stream, so a failure becomes a full-length timeout reported as
 * "Test timeout of 60000ms exceeded" — which names no step, and then usually
 * passes on the retry. That is the single most common shape in the recorded
 * CI flakes, and it is why this lives in its own helper rather than inside
 * ChatHelper: every surface that waits on a stream needs the same race.
 *
 * `message-done` on its own is also not proof of success: the error notice can
 * land on the same bubble, and its sentence is not part of
 * `section-message-text`, so reading the answer body afterwards would hang.
 */
export async function assertStreamSucceeded(bubble: Locator, timeout: number): Promise<void> {
  const errorNotice = bubble.locator(selectors.chat.chatError)

  const result = await Promise.race([
    bubble
      .locator(selectors.chat.messageDone)
      .waitFor({ state: 'visible', timeout })
      .then(() => 'done' as const),
    errorNotice.waitFor({ state: 'visible', timeout }).then(() => 'error' as const),
  ])

  if (result === 'error' || (await errorNotice.isVisible())) {
    const explanation = errorNotice.locator(selectors.chat.chatErrorBody)
    const text = (await explanation.isVisible()) ? (await explanation.innerText()).trim() : ''
    throw new Error(
      text
        ? `Assistant message ended in error state: ${text}`
        : 'Assistant message ended in error state (chat-error-notice visible)'
    )
  }
}
