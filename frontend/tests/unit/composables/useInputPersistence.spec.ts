/**
 * Tests for useAutoPersist / useInputPersistence — specifically the chatId
 * watcher that decides which draft to load when activeChatId changes.
 *
 * Critical scenario: activeChatId: null → realId while the user has already
 * typed. The text must NOT be wiped; it is carried forward into the new slot.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { ref, nextTick } from 'vue'
import { useAutoPersist } from '@/composables/useInputPersistence'

const STORAGE_PREFIX = 'synaplan_input_'

function slotKey(chatId: number | null): string {
  return chatId == null ? `${STORAGE_PREFIX}chat` : `${STORAGE_PREFIX}chat_${chatId}`
}

function writeSlot(chatId: number | null, text: string) {
  localStorage.setItem(slotKey(chatId), JSON.stringify({ message: text, timestamp: Date.now() }))
}

describe('useAutoPersist — chatId watcher', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  afterEach(() => {
    localStorage.clear()
    vi.restoreAllMocks()
  })

  it('real chat switch: loads the draft of the target chat', async () => {
    const chatId = ref<number | null>(1)
    const input = ref('')

    writeSlot(2, 'draft for chat 2')

    useAutoPersist(input, 'chat', chatId)

    chatId.value = 2
    await nextTick()

    expect(input.value).toBe('draft for chat 2')
  })

  it('null → realId: typed text is kept, not wiped', async () => {
    const chatId = ref<number | null>(null)
    const input = ref('hello world')

    useAutoPersist(input, 'chat', chatId)

    chatId.value = 42
    await nextTick()

    expect(input.value).toBe('hello world')
  })

  it('null → realId with existing draft: the draft wins', async () => {
    const chatId = ref<number | null>(null)
    const input = ref('')

    writeSlot(42, 'saved draft for 42')

    useAutoPersist(input, 'chat', chatId)

    chatId.value = 42
    await nextTick()

    expect(input.value).toBe('saved draft for 42')
  })

  it('real chat switch to chat with no draft: input is cleared', async () => {
    const chatId = ref<number | null>(1)
    const input = ref('leftover text')

    useAutoPersist(input, 'chat', chatId)

    chatId.value = 99
    await nextTick()

    expect(input.value).toBe('')
  })

  it('text from the left chat is flushed to its own storage slot', async () => {
    const chatId = ref<number | null>(7)
    const input = ref('unsaved text in chat 7')

    useAutoPersist(input, 'chat', chatId)

    chatId.value = 8
    await nextTick()

    const stored = localStorage.getItem(slotKey(7))
    expect(stored).not.toBeNull()
    const parsed = JSON.parse(stored!)
    expect(parsed.message).toBe('unsaved text in chat 7')
  })
})
