/**
 * How a widget send ends (AU4): while the transport retries a busy refusal,
 * the empty answer bubble says the server is busy; a turn that was never
 * taken drops both bubbles and gives the visitor their text back; any other
 * failure first looks in history, which may still hold the answer.
 */
import { WidgetUnavailableError } from '@/services/api/widgetsApi'

export { isBusyError } from '@/services/api/busyRetry'

export interface WidgetBubble {
  id: string
  role: string
  content: string
}

/** Show the busy sentence in the empty answer bubble while the transport retries. */
export function markWidgetBusy(
  messages: WidgetBubble[],
  assistantMessageId: string | null,
  status: string,
  text: string
): void {
  if (status !== 'busy' || !assistantMessageId) return
  const bubble = messages.find((m) => m.id === assistantMessageId)
  if (bubble && !bubble.content) {
    bubble.content = text
  }
}

/**
 * Fill a finished bubble from the result text when it shows nothing but the
 * busy sentence. Returns true when history has to answer for the bubble.
 */
export function settleWidgetBubble(
  messages: WidgetBubble[],
  assistantMessageId: string | null,
  busyText: string,
  resultText: string
): boolean {
  const last = messages[messages.length - 1]
  if (!last || last.id !== assistantMessageId) return false
  if (last.content && last.content !== busyText) return false
  if (resultText) {
    last.content = resultText
    return false
  }
  return true
}

/** After a failed send, history may still hold the answer; if not, say it failed. */
export async function recoverWidgetSend(
  messages: WidgetBubble[],
  assistantMessageId: string | null,
  isPreview: boolean,
  loadHistory: () => Promise<void>,
  sendFailedText: string,
  addBotMessage: (text: string) => void,
  scrollToBottom: () => Promise<void>
): Promise<void> {
  const lastMessage = messages.find((m) => m.id === assistantMessageId)
  let recovered = false

  if (!isPreview) {
    try {
      await loadHistory()
      const latestMessage = messages[messages.length - 1]
      if (
        latestMessage &&
        latestMessage.role === 'assistant' &&
        latestMessage.content.trim().length > 0
      ) {
        recovered = true
      }
    } catch (historyError) {
      if (!(historyError instanceof WidgetUnavailableError)) {
        console.error('Failed to recover conversation history:', historyError)
      }
    }
  }

  if (!recovered) {
    if (lastMessage && lastMessage.content.trim().length > 0) {
      return
    }
    const idx = messages.findIndex((m) => m.id === assistantMessageId)
    if (idx !== -1) {
      messages.splice(idx, 1)
    }
    addBotMessage(sendFailedText)
    return
  }
  await scrollToBottom()
}

/** A turn the server never took: drop both bubbles, put the text back in the box. */
export function endBusy(
  messages: WidgetBubble[],
  assistantMessageId: string | null,
  userMessage: string,
  setInput: (text: string) => void,
  sentence: string,
  addBotMessage: (text: string) => void
): void {
  const idx = messages.findIndex((m) => m.id === assistantMessageId)
  if (idx !== -1) {
    messages.splice(idx, 1)
  }
  for (let i = messages.length - 1; i >= 0; i--) {
    if (messages[i].role === 'user' && messages[i].content === userMessage) {
      messages.splice(i, 1)
      break
    }
  }
  setInput(userMessage)
  addBotMessage(sentence)
}
