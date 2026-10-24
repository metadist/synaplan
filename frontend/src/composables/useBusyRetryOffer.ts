import { useNotification } from './useNotification'

interface BusyInput {
  setInputText: (text: string) => void
  submitText: (text: string) => Promise<boolean>
}

type Translate = (key: string) => string

/**
 * A turn the server never took (AU4): the text goes back into the composer —
 * ChatInput keeps it as the chat's draft — and the sentence offers one
 * Try again that sends the same text once more.
 */
export function busyRefused(text: string, t: Translate, input: BusyInput | null | undefined): void {
  input?.setInputText(text)
  useNotification().push({
    type: 'warning',
    message: t('chatError.busyNotSent'),
    duration: 15000,
    action: {
      label: t('chatError.busyTryAgain'),
      onClick: () => {
        void input?.submitText(text)
      },
    },
  })
}
