import { describe, expect, it, vi } from 'vitest'
import { busyRefused, composerText } from '@/composables/useBusyRetryOffer'
import { useNotification } from '@/composables/useNotification'

const t = (key: string) => key

function fakeInput(draft: string) {
  return {
    setInputText: vi.fn(),
    submitText: vi.fn(async () => true),
    textareaRef: { value: { textareaRef: { value: { value: draft } } } },
  }
}

describe('busy refused offer', () => {
  it('reads the composer through the exposed textarea chain', () => {
    expect(composerText(fakeInput('next draft'))).toBe('next draft')
    expect(composerText(null)).toBe('')
  })

  it('restores an empty composer and resends from the same chat only', async () => {
    const input = fakeInput('')
    const { notifications, remove } = useNotification()
    const before = notifications.value.length

    busyRefused('Hello', t, input, () => true)
    expect(input.setInputText).toHaveBeenCalledWith('Hello')
    const note = notifications.value[notifications.value.length - 1]
    expect(note.message).toBe('chatError.busyNotSent')
    note.action?.onClick()
    expect(input.submitText).toHaveBeenCalledWith('Hello')
    remove(note.id)
    expect(notifications.value.length).toBe(before)
  })

  it('keeps a newer draft and refuses to resend into another chat', () => {
    const input = fakeInput('next draft')
    const { notifications, remove } = useNotification()

    busyRefused('Hello', t, input, () => false)
    expect(input.setInputText).not.toHaveBeenCalled()
    const note = notifications.value[notifications.value.length - 1]
    expect(note.message).toBe('chatError.busyKeptDraft')
    note.action?.onClick()
    expect(input.submitText).not.toHaveBeenCalled()
    remove(note.id)
  })
})
