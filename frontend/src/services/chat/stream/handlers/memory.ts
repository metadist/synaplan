/**
 * Long-term memory, feedback and deep-memory references of a signed-in
 * turn: the badges they render in the answer and the suggestions the user
 * may keep or delete. Registered for signed-in turns only, so a guest stream
 * can never reach them.
 */
import type { UserMemory } from '@/services/api/userMemoriesApi'
import type { StreamEventMetadata } from '@/types/chatStream'
import type { MemberTurn, StreamHandlers, StreamStatusHandler } from '../streamTurn'

function suggestedMemory(meta: StreamEventMetadata & { id: number }): UserMemory {
  return {
    id: meta.id,
    category: meta.category ?? '',
    key: meta.key ?? '',
    value: meta.value ?? '',
    source: (meta.source ?? 'auto_detected') as UserMemory['source'],
    messageId: meta.messageId ?? null,
    created: meta.created ?? Date.now(),
    updated: meta.updated ?? Date.now(),
  }
}

const memorySuggested: StreamStatusHandler<MemberTurn> = (data, turn) => {
  const meta = data.metadata
  if (!meta || !meta.id) {
    console.warn('⚠️ Invalid memory data received:', data)
    return
  }
  const memory = suggestedMemory({ ...meta, id: meta.id })
  if (meta.action === 'delete') {
    turn.memory.offerDelete(memory)
  } else {
    turn.memory.suggest(memory)
  }
}

/** The memories the answer may cite as `[Memory:ID]`; badges resolve them from the store. */
const memoriesLoaded: StreamStatusHandler<MemberTurn> = (data, turn) => {
  const rows = data.metadata?.memories
  if (!Array.isArray(rows)) return

  const store = turn.memory.memories
  for (const row of rows) {
    if (!store.memories.find((memory) => memory.id === row.id)) {
      store.memories.push({
        id: row.id,
        category: row.category || 'personal',
        key: row.key || '',
        value: row.value || '',
        source: (row.source || 'auto_detected') as UserMemory['source'],
        messageId: row.messageId ?? null,
        created: row.created || 0,
        updated: row.updated || 0,
      })
    }
  }
  const message = turn.message()
  if (message && rows.length > 0) {
    message.memoryIds = rows.map((row) => row.id)
  }
}

const feedbackLoaded: StreamStatusHandler<MemberTurn> = (data, turn) => {
  const rows = data.metadata?.feedbacks
  if (!Array.isArray(rows)) return

  const store = turn.memory.feedbacks
  for (const row of rows) {
    if (!store.getFeedbackById(row.id)) {
      store.feedbacks.push({
        id: row.id,
        type: row.type as 'false_positive' | 'positive',
        value: row.value ?? '',
        messageId: null,
        created: 0,
        updated: 0,
      })
    }
  }
  const message = turn.message()
  if (message && rows.length > 0) {
    message.feedbackIds = rows.map((row) => row.id)
  }
}

/** Deep-memory references the answer may cite as `[Message:ID]`. */
const digestsLoaded: StreamStatusHandler<MemberTurn> = (data, turn) => {
  const digests = data.metadata?.digests
  if (!Array.isArray(digests)) return

  turn.memory.digests.addReferences(
    digests
      .filter((digest) => digest && typeof digest.message_id === 'number')
      .map((digest) => ({
        messageId: digest.message_id,
        chatId: digest.chat_id ?? 0,
        title: digest.title ?? '',
        channel: digest.channel ?? '',
        sourceDate: digest.source_date ?? 0,
      }))
  )
}

/** Legacy event: drops the memory from the local store only. */
const memoryDeleted: StreamStatusHandler<MemberTurn> = (data, turn) => {
  const memoryId = data.metadata?.id
  if (memoryId) {
    turn.memory.memories.memories = turn.memory.memories.memories.filter(
      (memory) => memory.id !== memoryId
    )
  }
}

/** Phase timings, logged only after `localStorage.setItem('synaplanDebug', '1')`. */
const perf: StreamStatusHandler<MemberTurn> = (data, turn) => {
  try {
    if (typeof window === 'undefined' || !window.localStorage?.getItem('synaplanDebug')) return
    console.groupCollapsed(
      `[synaplan perf] ${data.total_ms ?? '?'} ms total — message ${turn.messageId}`
    )
    console.table(data.phases ?? {})
    if (data.marks && Object.keys(data.marks).length > 0) {
      console.table(data.marks)
    }
    console.groupEnd()
  } catch {
    // localStorage throws in private mode; the stream must go on.
  }
}

export const memoryHandlers: StreamHandlers<MemberTurn> = {
  memory_suggested: memorySuggested,
  memories_loaded: memoriesLoaded,
  feedback_loaded: feedbackLoaded,
  digests_loaded: digestsLoaded,
  memory_deleted: memoryDeleted,
  perf,
}
