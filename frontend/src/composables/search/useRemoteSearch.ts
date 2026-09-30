import { onScopeDispose, ref, watch, type Component, type Ref } from 'vue'
import {
  AdjustmentsHorizontalIcon,
  BoltIcon,
  ChatBubbleLeftRightIcon,
  ChatBubbleOvalLeftEllipsisIcon,
  DocumentTextIcon,
  LightBulbIcon,
  SparklesIcon,
} from '@heroicons/vue/24/outline'
import { ApiError } from '@/services/api/httpClient'
import {
  searchEverything,
  type SmartSearchHit,
  type SmartSearchKind,
} from '@/services/api/searchApi'
import { localizedSettingName } from './settingNames'
import type { SearchScope } from './useSmartSearch'
import type { SearchResult } from './types'

/** Quiet time after the last keystroke before the server is asked. */
export const REMOTE_DEBOUNCE_MS = 180
/** One letter matches everything; the local index already answers it. */
export const REMOTE_MIN_CHARS = 2
const REMOTE_LIMIT = 30

export type RemoteStatus = 'idle' | 'loading' | 'ready' | 'error' | 'rateLimited'

const KIND_ICONS: Record<SmartSearchKind, Component> = {
  chat: ChatBubbleLeftRightIcon,
  file: DocumentTextIcon,
  widget: ChatBubbleOvalLeftEllipsisIcon,
  assistant: SparklesIcon,
  task: BoltIcon,
  memory: LightBulbIcon,
  setting: AdjustmentsHorizontalIcon,
}

const SCOPE_KINDS: Partial<Record<SearchScope, SmartSearchKind[]>> = {
  file: ['file'],
  setting: ['setting'],
}

export function toSearchResult(hit: SmartSearchHit): SearchResult {
  const settingName =
    hit.kind === 'setting' ? localizedSettingName(hit.id.slice(`${hit.kind}:`.length)) : null
  return {
    id: hit.id,
    kind: hit.kind,
    title: settingName ?? hit.title,
    subtitle: hit.subtitle ?? undefined,
    snippet: hit.snippet ?? undefined,
    route: hit.route,
    icon: KIND_ICONS[hit.kind],
    matchedBy: hit.matchedBy,
    score: hit.score,
    setting: hit.action ?? undefined,
  }
}

/**
 * The server tiers of the palette (index, meaning, documents, memories).
 * Only the latest query may publish: every new keystroke aborts the
 * request in flight, so a slow answer never overwrites a newer one.
 */
export function useRemoteSearch(
  parsed: Ref<{ scope: SearchScope; text: string }>,
  isOpen: Ref<boolean>
) {
  const results = ref<SearchResult[]>([])
  const status = ref<RemoteStatus>('idle')
  const semanticAvailable = ref(true)
  const indexing = ref(false)

  let timer: ReturnType<typeof setTimeout> | null = null
  let controller: AbortController | null = null

  const cancel = () => {
    if (timer) clearTimeout(timer)
    timer = null
    controller?.abort()
    controller = null
  }

  const reset = () => {
    cancel()
    results.value = []
    status.value = 'idle'
  }

  const run = async (text: string, kinds: SmartSearchKind[] | undefined) => {
    const own = new AbortController()
    controller = own
    try {
      const response = await searchEverything(text, {
        kinds,
        limit: REMOTE_LIMIT,
        signal: own.signal,
      })
      if (own.signal.aborted) return
      results.value = response.results.map(toSearchResult)
      semanticAvailable.value = response.semanticAvailable
      indexing.value = response.indexing
      status.value = 'ready'
    } catch (error) {
      if (own.signal.aborted) return
      results.value = []
      status.value = error instanceof ApiError && error.status === 429 ? 'rateLimited' : 'error'
    } finally {
      if (controller === own) controller = null
    }
  }

  watch(
    [() => parsed.value.scope, () => parsed.value.text, isOpen],
    ([scope, text, open]) => {
      if (!open || scope === 'command' || text.length < REMOTE_MIN_CHARS) {
        reset()
        return
      }
      cancel()
      status.value = 'loading'
      timer = setTimeout(() => {
        timer = null
        void run(text, SCOPE_KINDS[scope])
      }, REMOTE_DEBOUNCE_MS)
    },
    { immediate: true }
  )

  onScopeDispose(cancel)

  return { results, status, semanticAvailable, indexing }
}
