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
  files?: Array<{ id?: number }>
  fileId?: number
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

/**
 * After a failed send, history may still hold the answer; if not, say it
 * failed. `loadHistory` replaces the message list, so the transcript is
 * re-read through the getter after it. The busy sentence is a status, not
 * an answer, and never counts as one.
 */
export async function recoverWidgetSend(
  getMessages: () => WidgetBubble[],
  assistantMessageId: string | null,
  busyText: string,
  isPreview: boolean,
  loadHistory: () => Promise<void>,
  sendFailedText: string,
  addBotMessage: (text: string) => void,
  scrollToBottom: () => Promise<void>
): Promise<void> {
  if (!isPreview) {
    try {
      await loadHistory()
    } catch (historyError) {
      if (!(historyError instanceof WidgetUnavailableError)) {
        console.error('Failed to recover conversation history:', historyError)
      }
    }
    const current = getMessages()
    const latest = current[current.length - 1]
    if (
      latest &&
      latest.role === 'assistant' &&
      latest.content.trim().length > 0 &&
      latest.content !== busyText
    ) {
      await scrollToBottom()
      return
    }
  }

  const messages = getMessages()
  const lastMessage = messages.find((m) => m.id === assistantMessageId)
  if (lastMessage && lastMessage.content.trim().length > 0 && lastMessage.content !== busyText) {
    return
  }
  const idx = messages.findIndex((m) => m.id === assistantMessageId)
  if (idx !== -1) {
    messages.splice(idx, 1)
  }
  addBotMessage(sendFailedText)
}

/**
 * A turn the server never took: the text goes back in the box, unless the
 * visitor already typed a newer draft or the send carried files — then the
 * bubble stays visible, with its files, and nothing is resent silently.
 */
export function endBusy(
  messages: WidgetBubble[],
  assistantMessageId: string | null,
  userMessage: string,
  currentInput: string,
  setInput: (text: string) => void,
  sentences: { restored: string; kept: string },
  addBotMessage: (text: string) => void
): void {
  const idx = messages.findIndex((m) => m.id === assistantMessageId)
  if (idx !== -1) {
    messages.splice(idx, 1)
  }
  let userIndex = -1
  for (let i = messages.length - 1; i >= 0; i--) {
    if (messages[i].role === 'user' && messages[i].content === userMessage) {
      userIndex = i
      break
    }
  }
  const userBubble = userIndex >= 0 ? messages[userIndex] : undefined
  const hasFiles = !!userBubble && ((userBubble.files?.length ?? 0) > 0 || !!userBubble.fileId)
  if (currentInput.trim() === '' && !hasFiles) {
    if (userIndex >= 0) {
      messages.splice(userIndex, 1)
    }
    setInput(userMessage)
    addBotMessage(sentences.restored)
    return
  }
  addBotMessage(sentences.kept)
}
