import type { Message } from '@/stores/history'

/** Text the action row can match against a stored transcript turn. */
export function spokenText(message: Pick<Message, 'parts'>): string {
  return message.parts
    .filter((part) => part.type === 'text')
    .map((part) => (part.content ?? '').trim())
    .join('\n')
}

/**
 * Attach the id of a turn the voice sidecar just stored.
 * The action buttons under a bubble stay hidden until that id exists.
 */
export function claimSpokenMessageId(
  local: Message[],
  role: 'user' | 'assistant',
  messageId: number,
  text: string
): void {
  if (!Number.isInteger(messageId) || messageId <= 0) return
  if (local.some((message) => message.backendMessageId === messageId)) return
  const trimmed = text.trim()
  const open = local.filter(
    (message) => message.role === role && message.backendMessageId === undefined
  )
  const exact = trimmed === '' ? undefined : open.find((message) => spokenText(message) === trimmed)
  const target = exact ?? (open.length === 1 ? open[0] : undefined)
  if (!target) return
  target.backendMessageId = messageId
}

export function hasUnboundSpoken(local: Message[], onlyIds: ReadonlySet<string>): boolean {
  return local.some(
    (message) =>
      onlyIds.has(message.id) &&
      message.backendMessageId === undefined &&
      spokenText(message) !== ''
  )
}

/**
 * Copy stored ids onto the spoken bubbles of this call. Each persisted row
 * is used once, and only a bubble from this call is touched.
 */
export function stampSpokenMessageIds(
  local: Message[],
  persisted: Message[],
  onlyIds: ReadonlySet<string>
): void {
  const used = new Set<number>()
  for (const message of local) {
    if (message.backendMessageId !== undefined) used.add(message.backendMessageId)
  }
  for (const message of local) {
    if (!onlyIds.has(message.id) || message.backendMessageId !== undefined) continue
    const text = spokenText(message)
    if (text === '') continue
    const match = persisted.find((row) => {
      if (row.role !== message.role || row.backendMessageId === undefined) return false
      if (used.has(row.backendMessageId)) return false
      return spokenText(row) === text
    })
    if (match?.backendMessageId === undefined) continue
    used.add(match.backendMessageId)
    message.backendMessageId = match.backendMessageId
    if ((message.topic === undefined || message.topic === '') && match.topic) {
      message.topic = match.topic
    }
    if (!message.aiModels && match.aiModels) message.aiModels = match.aiModels
    if (!message.usage && match.usage) message.usage = match.usage
    if (
      (!message.usageExtra || message.usageExtra.length === 0) &&
      match.usageExtra &&
      match.usageExtra.length > 0
    ) {
      message.usageExtra = match.usageExtra
    }
  }
}
