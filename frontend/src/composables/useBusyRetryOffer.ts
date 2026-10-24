import { useChatsStore } from '@/stores/chats'
import { useIncognitoStore } from '@/stores/incognito'
import { useNotification } from './useNotification'

/**
 * The resend guard for one turn: same chat, same mode, and — incognito —
 * the same session. Captured when the turn starts; the toast outlives a
 * switch to another conversation or a later incognito session.
 */
export function busyGuard(chatId: number | undefined, incognito: boolean): () => boolean {
  const chats = useChatsStore()
  const incognitoStore = useIncognitoStore()
  const session = incognitoStore.sessionId
  return () =>
    incognito
      ? incognitoStore.active && incognitoStore.sessionId === session
      : chats.activeChatId === chatId && !incognitoStore.active
}

interface BusyInput {
  setInputText: (text: string) => void
  submitText: (text: string) => Promise<boolean>
}

type Translate = (key: string) => string

/**
 * What the composer currently holds, read through the exposed textarea
 * chain. A newer draft typed while the transport retried is never
 * overwritten by the refused text.
 */
export function composerText(input: BusyInput | null | undefined): string {
  let node: unknown = (input as { textareaRef?: unknown } | null | undefined)?.textareaRef
  for (let i = 0; i < 4 && node && typeof node === 'object'; i++) {
    const record = node as Record<string, unknown>
    if (typeof record.value === 'string') return record.value
    node = 'textareaRef' in record ? record.textareaRef : record.value
  }
  return ''
}

/**
 * A turn the server never took (AU4): the text goes back into the composer —
 * ChatInput keeps it as the chat's draft — unless the user already typed a
 * newer draft there. The sentence offers one Try again that sends the same
 * text once more, and only while the view still shows the turn's own chat
 * and mode: the toast outlives a switch to another conversation.
 */
export function busyRefused(
  text: string,
  t: Translate,
  input: BusyInput | null | undefined,
  isCurrent: () => boolean
): void {
  const restored = composerText(input).trim() === ''
  if (restored) {
    input?.setInputText(text)
  }
  useNotification().push({
    type: 'warning',
    message: t(restored ? 'chatError.busyNotSent' : 'chatError.busyKeptDraft'),
    duration: 15000,
    action: {
      label: t('chatError.busyTryAgain'),
      onClick: () => {
        if (!isCurrent()) return
        // submitText replaces the composer: a draft typed meanwhile goes
        // back in afterwards, so the resend never eats it.
        const draft = composerText(input)
        void input?.submitText(text).then(() => {
          if (draft.trim() !== '' && draft !== text) {
            input?.setInputText(draft)
          }
        })
      },
    },
  })
}
