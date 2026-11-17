/** The fields of a chat message that decide where an approval card sits. */
export interface PlacementMessage {
  id: string | number
  role: string
  backendMessageId?: number
}

/** The field of an approval that names the message that asked for it. */
export interface PlacementApproval {
  requestedBy: { messageId?: number | null }
}

/**
 * Groups pending approvals by the answer bubble they belong in: the first
 * assistant message after the user message that asked for them. When that
 * message is not loaded, or no answer follows it yet, the card goes to the
 * last assistant message so it is never lost. One pass over the thread, so
 * the template can look a bubble up without rescanning on every render.
 */
export function placeApprovals<A extends PlacementApproval>(
  messages: readonly PlacementMessage[],
  approvals: readonly A[]
): Map<string | number, A[]> {
  const placed = new Map<string | number, A[]>()
  if (approvals.length === 0) {
    return placed
  }
  const answerTo = new Map<number, string | number>()
  let lastAssistant: string | number | undefined
  let waiting: number[] = []
  for (const message of messages) {
    if (message.role === 'user') {
      waiting = message.backendMessageId !== undefined ? [message.backendMessageId] : []
    } else if (message.role === 'assistant') {
      for (const asked of waiting) {
        if (!answerTo.has(asked)) answerTo.set(asked, message.id)
      }
      waiting = []
      lastAssistant = message.id
    }
  }
  for (const row of approvals) {
    const asked = row.requestedBy.messageId
    const target = (asked != null ? answerTo.get(asked) : undefined) ?? lastAssistant
    if (target === undefined) continue
    const list = placed.get(target)
    if (list) list.push(row)
    else placed.set(target, [row])
  }
  return placed
}
