import {
  resolveMessageDigests,
  type MessageDigestReference,
} from '@/services/api/messageDigestsApi'
import { defineStore } from 'pinia'
import { ref } from 'vue'

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
  const loading = ref(false)

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

  /**
   * Fetch references for ids not yet known. Best-effort: on network failure
   * nothing is marked unresolvable, so a later call can retry.
   */
  async function resolveMissing(messageIds: number[]): Promise<void> {
    const missing = Array.from(new Set(messageIds)).filter(
      (id) => id > 0 && !references.value.has(id) && !unresolvable.value.has(id)
    )
    if (missing.length === 0 || loading.value) return

    loading.value = true
    try {
      const resolved = await resolveMessageDigests(missing)
      addReferences(resolved)

      const resolvedIds = new Set(resolved.map((r) => r.messageId))
      const nextUnresolvable = new Set(unresolvable.value)
      for (const id of missing) {
        if (!resolvedIds.has(id)) nextUnresolvable.add(id)
      }
      unresolvable.value = nextUnresolvable
    } catch {
      // Network hiccup — leave ids unmarked so a later render retries.
    } finally {
      loading.value = false
    }
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
      loading.value = false
    },
  }
})
