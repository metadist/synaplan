/**
 * Open a chat scrolled to one message.
 *
 * Memories and [Message:ID] badges both use this. The URL is
 * `/?chat=<chatId>&message=<messageId>` (`openMessageLocation`). ChatView
 * strips both params after handling so a reload does not jump again.
 */

export const OPEN_MESSAGE_CHAT_QUERY = 'chat' as const
export const OPEN_MESSAGE_MESSAGE_QUERY = 'message' as const
export const MESSAGE_HIGHLIGHT_CLASS = 'message-target-highlight'

// A stuck hasMore flag must not page forever.
const MAX_OLDER_PAGES = 200

export interface OpenMessageTarget {
  chatId: number
  messageId: number
}

export interface OpenMessageLocation {
  name: 'chat'
  query: Record<typeof OPEN_MESSAGE_CHAT_QUERY | typeof OPEN_MESSAGE_MESSAGE_QUERY, string>
}

export type OpenSourceMessageResult = 'found' | 'missing' | 'gone' | 'cancelled'

export interface OpenSourceMessageDeps {
  /** Switch to the chat if needed. Resolve after its messages are loaded and rendered. */
  ensureChatReady: (chatId: number) => Promise<'ready' | 'gone'>
  isMessageLoaded: (messageId: number) => boolean
  hasMoreMessages: () => boolean
  loadOlderMessages: () => Promise<void>
  loadedCount: () => number
  findElement: (messageId: number) => HTMLElement | null
  nextTick: () => Promise<void>
  /** Mark the following scroll as programmatic (sync, before scrollIntoView). */
  beforeScroll?: () => void
  afterScroll?: () => void
  notifyMissing: () => void
  isCancelled?: () => boolean
}

export function parsePositiveQueryId(value: unknown): number | null {
  const raw = Array.isArray(value) ? value[0] : value
  if (typeof raw !== 'string' && typeof raw !== 'number') return null
  if (typeof raw === 'string' && raw.trim() === '') return null
  const parsed = typeof raw === 'number' ? raw : Number(raw)
  if (!Number.isInteger(parsed) || parsed <= 0) return null
  return parsed
}

export function parseOpenMessageQuery(query: {
  readonly chat?: unknown
  readonly message?: unknown
}): OpenMessageTarget | null {
  const chatId = parsePositiveQueryId(query.chat)
  const messageId = parsePositiveQueryId(query.message)
  if (chatId === null || messageId === null) return null
  return { chatId, messageId }
}

export function openMessageLocation(chatId: number, messageId: number): OpenMessageLocation {
  return {
    name: 'chat',
    query: {
      [OPEN_MESSAGE_CHAT_QUERY]: String(chatId),
      [OPEN_MESSAGE_MESSAGE_QUERY]: String(messageId),
    },
  }
}

let highlightedMessage: HTMLElement | null = null

export function highlightMessageElement(element: HTMLElement): void {
  if (highlightedMessage && highlightedMessage !== element) {
    highlightedMessage.classList.remove(MESSAGE_HIGHLIGHT_CLASS)
  }
  highlightedMessage = element
  element.classList.remove(MESSAGE_HIGHLIGHT_CLASS)
  // Restart the CSS animation when the same message is opened again.
  void element.offsetWidth
  element.classList.add(MESSAGE_HIGHLIGHT_CLASS)

  const onAnimationEnd = (event: AnimationEvent) => {
    if (event.target !== element) return
    element.classList.remove(MESSAGE_HIGHLIGHT_CLASS)
    element.removeEventListener('animationend', onAnimationEnd)
    if (highlightedMessage === element) highlightedMessage = null
  }
  element.addEventListener('animationend', onAnimationEnd)

  const reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false
  if (!reduceMotion) return

  const clear = () => {
    element.classList.remove(MESSAGE_HIGHLIGHT_CLASS)
    if (highlightedMessage === element) highlightedMessage = null
    document.removeEventListener('pointerdown', clear, true)
  }
  document.addEventListener('pointerdown', clear, true)
}

export async function openSourceMessage(
  target: OpenMessageTarget,
  deps: OpenSourceMessageDeps
): Promise<OpenSourceMessageResult> {
  if (deps.isCancelled?.()) return 'cancelled'

  const status = await deps.ensureChatReady(target.chatId)
  if (deps.isCancelled?.()) return 'cancelled'
  if (status === 'gone') return 'gone'

  const found = await loadUntilMessagePresent(target.messageId, deps)
  if (deps.isCancelled?.()) return 'cancelled'
  if (!found) {
    deps.notifyMissing()
    return 'missing'
  }

  await deps.nextTick()
  if (deps.isCancelled?.()) return 'cancelled'

  const element = deps.findElement(target.messageId)
  if (!element) {
    deps.notifyMissing()
    return 'missing'
  }

  deps.beforeScroll?.()
  element.scrollIntoView({ block: 'center', inline: 'nearest' })
  deps.afterScroll?.()
  highlightMessageElement(element)
  return 'found'
}

async function loadUntilMessagePresent(
  messageId: number,
  deps: OpenSourceMessageDeps
): Promise<boolean> {
  if (deps.isMessageLoaded(messageId)) return true

  for (let page = 0; page < MAX_OLDER_PAGES; page++) {
    if (deps.isCancelled?.()) return false
    if (!deps.hasMoreMessages()) return false
    const before = deps.loadedCount()
    await deps.loadOlderMessages()
    if (deps.isCancelled?.()) return false
    if (deps.isMessageLoaded(messageId)) return true
    if (deps.loadedCount() === before) return false
  }

  return false
}
