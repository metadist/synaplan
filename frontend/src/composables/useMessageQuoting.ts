import { onMounted, onUnmounted, ref, type Ref } from 'vue'

/**
 * A piece of text the user selected inside a chat message and wants to
 * reference in their next message ("Mention in chat", like Claude).
 */
export interface QuotedReference {
  text: string
  /** Backend message id of the source message, when available. */
  messageId?: number
  /** Role of the source message ('user' | 'assistant'). */
  role?: string
}

export interface FloatingButtonPosition {
  /** Viewport-relative top edge of the selection rect. */
  top: number
  /** Viewport-relative bottom edge of the selection rect. */
  bottom: number
  /** Viewport-relative horizontal center of the selection rect. */
  left: number
}

const MIN_SELECTION_LENGTH = 2
const MAX_QUOTE_LENGTH = 4000

/**
 * Render a quoted reference as a Markdown blockquote prefix for messages that
 * are sent verbatim to a human recipient (e.g. operator replies in LiveSupport
 * and Widget Sessions, where there is no structured backend quote field).
 */
export function formatQuoteAsBlockquote(text: string): string {
  return text
    .split('\n')
    .map((line) => `> ${line}`)
    .join('\n')
}

/**
 * Tracks text selections inside a chat scroll container and exposes the state
 * needed to render a floating "quote" button above the selection plus the
 * confirmed quote that the composer should attach to the next message.
 *
 * Only selections that originate inside an element marked with
 * `data-quotable` (and live within `rootRef`) are considered quotable. The
 * source message id/role are read from `data-message-id` / `data-message-role`
 * on that element.
 */
export function useMessageQuoting(rootRef: Ref<HTMLElement | null>) {
  const floatingVisible = ref(false)
  const floatingPosition = ref<FloatingButtonPosition>({ top: 0, bottom: 0, left: 0 })
  const pendingQuote = ref<QuotedReference | null>(null)

  // Raw selection captured on mouseup, awaiting confirmation via the button.
  let activeSelection: QuotedReference | null = null
  let activeRange: Range | null = null

  const hideButton = () => {
    floatingVisible.value = false
    activeSelection = null
    activeRange = null
  }

  const findQuotable = (node: Node | null): HTMLElement | null => {
    let current: Node | null = node
    while (current && current !== rootRef.value) {
      if (current instanceof HTMLElement && current.hasAttribute('data-quotable')) {
        return current
      }
      current = current.parentNode
    }
    return null
  }

  const positionFromRange = (range: Range): FloatingButtonPosition | null => {
    const rect = range.getBoundingClientRect()
    if (rect.width === 0 && rect.height === 0) return null
    return {
      top: rect.top,
      bottom: rect.bottom,
      left: rect.left + rect.width / 2,
    }
  }

  const evaluateSelection = () => {
    const root = rootRef.value
    if (!root) {
      hideButton()
      return
    }

    const selection = window.getSelection()
    if (!selection || selection.isCollapsed || selection.rangeCount === 0) {
      hideButton()
      return
    }

    const text = selection.toString().trim()
    if (text.length < MIN_SELECTION_LENGTH) {
      hideButton()
      return
    }

    const range = selection.getRangeAt(0)
    const quotable = findQuotable(range.commonAncestorContainer)
    if (!quotable || !root.contains(quotable)) {
      hideButton()
      return
    }

    const position = positionFromRange(range)
    if (!position) {
      hideButton()
      return
    }

    const messageIdRaw = quotable.dataset.messageId
    const messageId = messageIdRaw ? Number(messageIdRaw) : undefined

    activeSelection = {
      text: text.slice(0, MAX_QUOTE_LENGTH),
      messageId: messageId !== undefined && Number.isFinite(messageId) ? messageId : undefined,
      role: quotable.dataset.messageRole,
    }
    activeRange = range
    floatingPosition.value = position
    floatingVisible.value = true
  }

  const onMouseUp = () => {
    // Defer so the browser has finalized the selection before we read it.
    window.setTimeout(evaluateSelection, 0)
  }

  const onSelectionChange = () => {
    const selection = window.getSelection()
    if (!selection || selection.isCollapsed || selection.toString().trim().length === 0) {
      hideButton()
    }
  }

  const reposition = () => {
    if (!floatingVisible.value || !activeRange) return
    const position = positionFromRange(activeRange)
    if (position) {
      floatingPosition.value = position
    } else {
      hideButton()
    }
  }

  const confirmQuote = () => {
    if (!activeSelection) return
    pendingQuote.value = { ...activeSelection }
    hideButton()
    window.getSelection()?.removeAllRanges()
  }

  const clearPendingQuote = () => {
    pendingQuote.value = null
  }

  onMounted(() => {
    document.addEventListener('mouseup', onMouseUp)
    document.addEventListener('selectionchange', onSelectionChange)
    window.addEventListener('scroll', reposition, true)
    window.addEventListener('resize', reposition)
  })

  onUnmounted(() => {
    document.removeEventListener('mouseup', onMouseUp)
    document.removeEventListener('selectionchange', onSelectionChange)
    window.removeEventListener('scroll', reposition, true)
    window.removeEventListener('resize', reposition)
  })

  return {
    floatingVisible,
    floatingPosition,
    pendingQuote,
    confirmQuote,
    clearPendingQuote,
    hideButton,
  }
}
