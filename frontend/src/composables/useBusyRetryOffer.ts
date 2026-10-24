import { useNotification } from './useNotification'

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
        void input?.submitText(text)
      },
    },
  })
}
