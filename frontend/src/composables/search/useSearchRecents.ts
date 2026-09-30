import { computed, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import type { SearchKind, SearchResult } from './types'

export interface StoredRecent {
  id: string
  kind: SearchKind
  title: string
  subtitle?: string
  route?: string
}

const MAX_RECENTS = 8
const STORAGE_PREFIX = 'synaplan.search.recents.'

function read(key: string): StoredRecent[] {
  try {
    const raw = localStorage.getItem(key)
    const parsed: unknown = raw ? JSON.parse(raw) : []
    if (!Array.isArray(parsed)) return []
    return parsed.filter(
      (entry): entry is StoredRecent =>
        typeof entry === 'object' &&
        entry !== null &&
        typeof (entry as StoredRecent).id === 'string' &&
        typeof (entry as StoredRecent).title === 'string'
    )
  } catch {
    return []
  }
}

/**
 * Recently opened palette results, per user and per device. Only ids,
 * titles and routes are stored — never snippets or file content.
 */
export function useSearchRecents() {
  const authStore = useAuthStore()
  const storageKey = computed(() => `${STORAGE_PREFIX}${authStore.user?.id ?? 'guest'}`)
  const recents = ref<StoredRecent[]>(read(storageKey.value))

  watch(storageKey, (key) => {
    recents.value = read(key)
  })

  const remember = (result: SearchResult) => {
    if (result.kind === 'ask' || result.kind === 'best') return
    const entry: StoredRecent = {
      id: result.id,
      kind: result.kind,
      title: result.title,
      subtitle: result.subtitle,
      route: result.route,
    }
    recents.value = [entry, ...recents.value.filter((r) => r.id !== entry.id)].slice(0, MAX_RECENTS)
    try {
      localStorage.setItem(storageKey.value, JSON.stringify(recents.value))
    } catch {
      // Storage blocked (private mode) — recents are optional.
    }
  }

  const forget = (id: string) => {
    recents.value = recents.value.filter((r) => r.id !== id)
    try {
      localStorage.setItem(storageKey.value, JSON.stringify(recents.value))
    } catch {
      // Storage blocked — nothing to clean up.
    }
  }

  return { recents, remember, forget }
}
