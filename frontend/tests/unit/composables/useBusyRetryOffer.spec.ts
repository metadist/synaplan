import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it, vi } from 'vitest'
import { busyGuard, busyRefused, composerText } from '@/composables/useBusyRetryOffer'
import { useNotification } from '@/composables/useNotification'
import { useChatsStore } from '@/stores/chats'
import { useIncognitoStore } from '@/stores/incognito'

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

  it('puts a draft typed during the toast back after the resend', async () => {
    const input = fakeInput('next draft')
    const { notifications, remove } = useNotification()

    busyRefused('Hello', t, input, () => true)
    const note = notifications.value[notifications.value.length - 1]
    note.action?.onClick()
    expect(input.submitText).toHaveBeenCalledWith('Hello')
    await vi.waitFor(() => {
      expect(input.setInputText).toHaveBeenCalledWith('next draft')
    })
    remove(note.id)
  })

  it('binds a resend to its chat and to its incognito session', async () => {
    setActivePinia(createPinia())
    const chats = useChatsStore()
    const incognito = useIncognitoStore()

    chats.activeChatId = 42
    const chatGuard = busyGuard(42, false)
    expect(chatGuard()).toBe(true)
    chats.activeChatId = 7
    expect(chatGuard()).toBe(false)
    chats.activeChatId = 42

    incognito.startSession()
    const incognitoGuard = busyGuard(undefined, true)
    expect(incognitoGuard()).toBe(true)
    await incognito.endSession()
    expect(incognitoGuard()).toBe(false)
    incognito.startSession()
    expect(incognitoGuard()).toBe(false)
    await incognito.endSession()
  })
})
