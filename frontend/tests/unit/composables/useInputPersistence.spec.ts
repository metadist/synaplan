/**
 * Tests for useAutoPersist / useInputPersistence — specifically the chatId
 * watcher that decides which draft to load when activeChatId changes.
 *
 * Critical scenario: activeChatId: null → realId while the user has already
 * typed. The text must NOT be wiped; it is carried forward into the new slot.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { ref, nextTick } from 'vue'
import {
  useAttachmentPersist,
  useAutoPersist,
  usePastedBlocksPersist,
} from '@/composables/useInputPersistence'

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

  it('null → realId: an in-progress upload is kept', async () => {
    const chatId = ref<number | null>(null)
    const files = ref([
      {
        file_id: 0,
        filename: 'most_important_thing.txt',
        file_type: 'txt',
        name: 'most_important_thing.txt',
        processing: true,
      },
    ])

    useAttachmentPersist(files, 'chat', chatId)

    chatId.value = 7
    await nextTick()

    expect(files.value).toEqual([
      {
        file_id: 0,
        filename: 'most_important_thing.txt',
        file_type: 'txt',
        name: 'most_important_thing.txt',
        processing: true,
      },
    ])
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

interface ComposerFile {
  file_id: number
  filename: string
  file_type: string
  name?: string
  processing: boolean
  staged?: boolean
}

function attachKey(chatId: number | null): string {
  return chatId == null ? `${STORAGE_PREFIX}attach_chat` : `${STORAGE_PREFIX}attach_chat_${chatId}`
}

function writeAttachments(chatId: number | null, files: Array<Omit<ComposerFile, 'processing'>>) {
  localStorage.setItem(attachKey(chatId), JSON.stringify({ files, timestamp: Date.now() }))
}

const ready = (id: number, filename: string): ComposerFile => ({
  file_id: id,
  filename,
  file_type: 'txt',
  processing: false,
  staged: true,
})

describe('useAttachmentPersist — chatId watcher', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  afterEach(() => {
    localStorage.clear()
  })

  it('real chat switch: loads the attachments of the target chat', async () => {
    const chatId = ref<number | null>(1)
    const files = ref<ComposerFile[]>([ready(10, 'one.txt')])
    writeAttachments(2, [{ file_id: 20, filename: 'two.txt', file_type: 'txt' }])

    useAttachmentPersist(files, 'chat', chatId)
    chatId.value = 2
    await nextTick()

    expect(files.value.map((f) => f.file_id)).toEqual([20])
  })

  it('null → realId: attachments added before the chat had an id are kept, uploads included', async () => {
    const chatId = ref<number | null>(null)
    const uploading: ComposerFile = {
      file_id: 0,
      filename: 'draft.txt',
      file_type: 'txt',
      name: 'draft.txt',
      processing: true,
      staged: true,
    }
    const files = ref<ComposerFile[]>([ready(10, 'one.txt'), uploading])

    useAttachmentPersist(files, 'chat', chatId)
    chatId.value = 42
    await nextTick()

    expect(files.value).toEqual([ready(10, 'one.txt'), uploading])
    expect(localStorage.getItem(attachKey(null))).toBeNull()
    const stored = JSON.parse(localStorage.getItem(attachKey(42))!)
    expect(stored.files.map((f: ComposerFile) => f.file_id)).toEqual([10])
  })

  it('null → realId with saved attachments for that chat: the saved ones win', async () => {
    const chatId = ref<number | null>(null)
    const files = ref<ComposerFile[]>([])
    writeAttachments(42, [{ file_id: 30, filename: 'saved.txt', file_type: 'txt' }])

    useAttachmentPersist(files, 'chat', chatId)
    chatId.value = 42
    await nextTick()

    expect(files.value.map((f) => f.file_id)).toEqual([30])
  })
})

describe('usePastedBlocksPersist — chatId watcher', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  afterEach(() => {
    localStorage.clear()
  })

  it('null → realId: pasted blocks are kept and move to the new chat', async () => {
    const chatId = ref<number | null>(null)
    const blocks = ref([{ id: 'b1', content: 'pasted log' }])

    usePastedBlocksPersist(blocks, 'chat', chatId)
    chatId.value = 42
    await nextTick()

    expect(blocks.value).toEqual([{ id: 'b1', content: 'pasted log' }])
    expect(localStorage.getItem(`${STORAGE_PREFIX}pasted_chat`)).toBeNull()
    expect(localStorage.getItem(`${STORAGE_PREFIX}pasted_chat_42`)).not.toBeNull()
  })

  it('real chat switch: blocks of the left chat do not follow', async () => {
    const chatId = ref<number | null>(1)
    const blocks = ref([{ id: 'b1', content: 'chat 1 paste' }])

    usePastedBlocksPersist(blocks, 'chat', chatId)
    chatId.value = 2
    await nextTick()

    expect(blocks.value).toEqual([])
  })
})
