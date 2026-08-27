import {
  resolveMessageDigests,
  type MessageDigestReference,
} from '@/services/api/messageDigestsApi'
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

/** The resolve endpoint caps a single request at 100 ids. */
const CHUNK_SIZE = 100

/**
 * Deep-memory digest references for [Message:ID] badges.
 *
 * References arrive two ways: pushed via the `digests_loaded` SSE event
 * during streaming, and lazily fetched for tags found in history messages
 * after a page reload. Ids the backend does not know (invented, foreign,
 * deleted) are remembered as unresolvable so we never refetch them.
 */
export const useMessageDigestsStore = defineStore('messageDigests', () => {
  const references = ref<Map<number, MessageDigestReference>>(new Map())
  const unresolvable = ref<Set<number>>(new Set())
  const requestsInFlight = ref(0)
  const loading = computed(() => requestsInFlight.value > 0)

  // Ids waiting for the next flush, and ids a running request already covers.
  // Deliberately outside the reactive state: they only gate what goes into the
  // next request, nothing renders off them.
  let queued = new Set<number>()
  const fetching = new Set<number>()
  let flush: Promise<void> | null = null

  function addReferences(refs: MessageDigestReference[]): void {
    if (refs.length === 0) return
    const next = new Map(references.value)
    for (const reference of refs) {
      if (reference.messageId > 0) {
        next.set(reference.messageId, reference)
        unresolvable.value.delete(reference.messageId)
      }
    }
    references.value = next
  }

  function getByMessageId(messageId: number): MessageDigestReference | undefined {
    return references.value.get(messageId)
  }

  function isUnresolvable(messageId: number): boolean {
    return unresolvable.value.has(messageId)
  }

  async function fetchQueued(): Promise<void> {
    const batch = Array.from(queued)
    queued = new Set()
    flush = null
    if (batch.length === 0) return

    for (const id of batch) fetching.add(id)
    requestsInFlight.value += 1
    try {
      const resolved: MessageDigestReference[] = []
      for (let i = 0; i < batch.length; i += CHUNK_SIZE) {
        resolved.push(...(await resolveMessageDigests(batch.slice(i, i + CHUNK_SIZE))))
      }
      addReferences(resolved)

      const resolvedIds = new Set(resolved.map((r) => r.messageId))
      const nextUnresolvable = new Set(unresolvable.value)
      for (const id of batch) {
        if (!resolvedIds.has(id)) nextUnresolvable.add(id)
      }
      unresolvable.value = nextUnresolvable
    } catch {
      // Network hiccup — leave ids unmarked so a later render retries.
    } finally {
      for (const id of batch) fetching.delete(id)
      requestsInFlight.value -= 1
    }
  }

  /**
   * Fetch references for ids not yet known. Best-effort: on network failure
   * nothing is marked unresolvable, so a later call can retry.
   *
   * Callers of the same tick are coalesced into ONE request. A page reload
   * mounts every history message at once, so a "first caller wins, the rest
   * are dropped" guard would leave all but one bubble stuck on a loading
   * badge forever — the store re-render they wake up on does not re-request.
   */
  async function resolveMissing(messageIds: number[]): Promise<void> {
    for (const id of messageIds) {
      if (id > 0 && !references.value.has(id) && !unresolvable.value.has(id) && !fetching.has(id)) {
        queued.add(id)
      }
    }
    if (queued.size === 0) return

    flush ??= Promise.resolve().then(fetchQueued)
    await flush
  }

  return {
    references,
    unresolvable,
    loading,
    addReferences,
    getByMessageId,
    isUnresolvable,
    resolveMissing,
    $reset() {
      references.value = new Map()
      unresolvable.value = new Set()
      requestsInFlight.value = 0
      queued = new Set()
      fetching.clear()
      flush = null
    },
  }
})
