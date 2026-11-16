import type { IncognitoHistoryEntry } from '@/services/api/chatApi'
import type { Message } from '@/stores/history'

/**
 * The in-memory incognito transcript for the backend: prior turns only (the
 * current user message travels as `message`, the freshly created assistant
 * placeholder is still streaming). The backend caps the payload at ~30
 * entries / 15k chars, so no client-side cap is needed.
 *
 * A failed turn is left out, as the backend leaves it out of a stored chat:
 * the answer that carries an error reason and the question it failed on. The
 * model never answered it, and a question a provider refused is refused again
 * when it is replayed.
 */
export function incognitoHistory(messages: Message[]): IncognitoHistoryEntry[] {
  const entries: IncognitoHistoryEntry[] = []
  for (const m of messages) {
    if (m.isStreaming) continue
    if (m.role === 'assistant' && m.errorReason) {
      if (entries.at(-1)?.role === 'user') entries.pop()
      continue
    }
    const text = m.parts
      .filter((p) => p.type === 'text' && p.content)
      .map((p) => p.content as string)
      .join('\n')
      .trim()
    if (!text) continue
    entries.push({ role: m.role, content: text })
  }
  if (entries.at(-1)?.role === 'user') entries.pop()
  return entries
}
