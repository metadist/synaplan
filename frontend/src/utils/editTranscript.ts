import type { Message, Part } from '@/stores/history'

/** Joined text the user bubble shows. An edit replaces this, not file parts. */
export function questionText(parts: Part[]): string {
  return parts
    .filter((part) => part.type === 'text')
    .map((part) => part.content || '')
    .join('\n')
}

function withQuestionText(parts: Part[], text: string): Part[] {
  const kept = parts.filter((part) => part.type !== 'text')
  return [{ type: 'text', content: text }, ...kept]
}

/**
 * The saved wording replaces the question in place, and the branch that
 * belonged to the previous wording leaves the visible transcript. The stream
 * that follows appends the new answer and sends this same string, so that
 * answer belongs to the text on screen.
 */
export function transcriptAfterSavedEdit(
  messages: Message[],
  editedId: string,
  text: string
): Message[] {
  const index = messages.findIndex((message) => message.id === editedId)
  if (index < 0 || messages[index].role !== 'user') return messages

  const edited: Message = {
    ...messages[index],
    parts: withQuestionText(messages[index].parts, text),
  }

  return [...messages.slice(0, index), edited]
}

/**
 * Again must not reload: a reload while the next Again opens replaces the
 * bubble and closes its model list. An edit reloads after the link so the
 * stepper matches the rows the server kept, and only while that chat is
 * still the one on screen.
 */
export function shouldReloadTranscriptAfterVersionLink(input: {
  kind: 'again' | 'edit'
  incognito: boolean
  editedChatId: number | null | undefined
  activeChatId: number | null | undefined
}): boolean {
  return (
    input.kind === 'edit' &&
    !input.incognito &&
    input.editedChatId != null &&
    input.editedChatId === input.activeChatId
  )
}
