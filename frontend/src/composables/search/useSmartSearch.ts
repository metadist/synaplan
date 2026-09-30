import { computed, ref, watch, type Ref } from 'vue'
import { useRouter } from 'vue-router'
import { ChatBubbleLeftEllipsisIcon, ClockIcon, SparklesIcon } from '@heroicons/vue/24/outline'
import { i18n } from '@/i18n/instance'
import { LocalSearchIndex } from './localIndex'
import { ensureAllLocales } from './localeTexts'
import { usePageSources, type LocalEntry } from './pageSources'
import { useCommandSources } from './commandSources'
import { useSearchRecents } from './useSearchRecents'
import { useRemoteSearch } from './useRemoteSearch'
import { useSearchInterpret } from './useSearchInterpret'
import { buildBestAction } from './bestAction'
import { isExactMatch, orderKinds } from './groupOrder'
import type { SearchGroup, SearchKind, SearchResult } from './types'

const MAX_PER_GROUP = 6
/** A prefix (`>`, `#`, `@`) narrows to one kind, so show more of it. */
const MAX_SCOPED = 30
const SUGGESTED_IDS = ['command:new-chat', 'command:upload', 'page:/settings', 'page:/files']

export type SearchScope = 'all' | 'command' | 'setting' | 'file'

/** `>` commands, `#` settings, `@` files — the rest of the text is the query. */
export function parseScope(raw: string): { scope: SearchScope; text: string } {
  const first = raw.trimStart().charAt(0)
  const rest = raw.trimStart().slice(1).trim()
  if (first === '>') return { scope: 'command', text: rest }
  if (first === '#') return { scope: 'setting', text: rest }
  if (first === '@') return { scope: 'file', text: rest }
  return { scope: 'all', text: raw.trim() }
}

/**
 * Stable re-order that lifts recently opened items to the top of their
 * group, most recent first; everything else keeps its ranked order. An
 * exact match of the query stays above them all.
 */
export function boostRecent(items: SearchResult[], recentIds: string[], text = ''): SearchResult[] {
  const rank = new Map(recentIds.map((id, index) => [id, index]))
  const exact = (item: SearchResult) => (isExactMatch(item, text) ? 0 : 1)
  return items
    .map((item, index) => ({ item, index, recent: rank.get(item.id) ?? Number.MAX_SAFE_INTEGER }))
    .sort((a, b) => exact(a.item) - exact(b.item) || a.recent - b.recent || a.index - b.index)
    .map((entry) => entry.item)
}

export function useSmartSearch(isOpen: Ref<boolean>, onClose: () => void) {
  const router = useRouter()
  const t = i18n.global.t
  const query = ref('')
  const index = new LocalSearchIndex()

  const { entries: pageEntries } = usePageSources()
  const { entries: commandEntries, prefillChat } = useCommandSources()
  const { recents, remember } = useSearchRecents()

  const localEntries = computed<LocalEntry[]>(() => [...commandEntries.value, ...pageEntries.value])
  const localById = computed(
    () => new Map(localEntries.value.map((entry) => [entry.result.id, entry.result]))
  )

  watch(
    [localEntries, () => i18n.global.locale.value],
    () => index.rebuild(localEntries.value.map((entry) => entry.doc)),
    { immediate: true }
  )

  watch(isOpen, (open) => {
    if (open) void ensureAllLocales()
  })

  const parsed = computed(() => parseScope(query.value))
  const remote = useRemoteSearch(parsed, isOpen)

  const localResults = computed<SearchResult[]>(() => {
    const { scope, text } = parsed.value
    if (text === '' || scope === 'setting' || scope === 'file') {
      if (scope === 'command' && text === '') {
        return commandEntries.value.map((entry) => entry.result)
      }
      return []
    }
    return index
      .search(text)
      .map((hit): SearchResult | null => {
        const result = localById.value.get(hit.id)
        return result ? { ...result, score: hit.score } : null
      })
      .filter((result): result is SearchResult => result !== null)
      .filter((result) => scope === 'all' || result.kind === 'command')
  })

  const askResult = computed<SearchResult | null>(() => {
    const text = parsed.value.text
    if (text === '') return null
    return {
      id: 'ask:chat',
      kind: 'ask',
      title: String(t('search.palette.askInChat', { query: text })),
      icon: ChatBubbleLeftEllipsisIcon,
      matchedBy: 'local',
      run: () => prefillChat(text),
    }
  })

  /** Everything the list found, once per id — what the AI may point at. */
  const candidates = computed<SearchResult[]>(() => {
    const seen = new Set<string>()
    return [...localResults.value, ...remote.results.value].filter((result) => {
      if (seen.has(result.id)) return false
      seen.add(result.id)
      return true
    })
  })

  const ai = useSearchInterpret({
    text: computed(() => (parsed.value.scope === 'all' ? parsed.value.text : '')),
    candidates,
    remoteStatus: remote.status,
    isOpen,
  })

  const askAiResult = computed<SearchResult | null>(() => {
    const { scope, text } = parsed.value
    if (!ai.enabled.value || scope !== 'all' || text === '') return null
    if (ai.status.value !== 'idle' && ai.status.value !== 'failed') return null
    if (candidates.value.length === 0) return null
    return {
      id: 'ask:ai',
      kind: 'ask',
      title: String(t('search.palette.ai.ask')),
      icon: SparklesIcon,
      matchedBy: 'local',
      keepOpen: true,
      run: () => ai.ask(),
    }
  })

  const best = computed(() => buildBestAction(ai.result.value, candidates.value, askResult.value))

  const recentResults = computed<SearchResult[]>(() =>
    recents.value
      .map((recent) => {
        const local = localById.value.get(recent.id)
        if (local) return local
        if (!recent.route) return null
        return {
          id: recent.id,
          kind: recent.kind,
          title: recent.title,
          subtitle: recent.subtitle,
          route: recent.route,
          icon: ClockIcon,
          matchedBy: 'local' as const,
        }
      })
      .filter((result): result is SearchResult => result !== null)
  )

  const groupLabel = (key: string) => String(t(`search.palette.groups.${key}`))

  const groups = computed<SearchGroup[]>(() => {
    if (parsed.value.text === '' && parsed.value.scope === 'all') {
      const suggested = SUGGESTED_IDS.map((id) => localById.value.get(id)).filter(
        (result): result is SearchResult =>
          result !== undefined && !recents.value.some((recent) => recent.id === result.id)
      )
      const emptyView: SearchGroup[] = [
        { key: 'recent', label: groupLabel('recent'), items: recentResults.value },
        { key: 'suggested', label: groupLabel('suggested'), items: suggested },
      ]
      return emptyView.filter((group) => group.items.length > 0)
    }

    const byKind = new Map<SearchKind, SearchResult[]>()
    const picked = new Set(best.value?.items.map((item) => item.id) ?? [])
    const all = [
      ...candidates.value,
      ...(askAiResult.value ? [askAiResult.value] : []),
      ...(askResult.value ? [askResult.value] : []),
    ].filter((result) => !picked.has(result.id))
    for (const result of all) {
      const list = byKind.get(result.kind) ?? []
      list.push(result)
      byKind.set(result.kind, list)
    }
    const limit = parsed.value.scope === 'all' ? MAX_PER_GROUP : MAX_SCOPED
    const recentIds = recents.value.map((recent) => recent.id)
    const ranked: SearchGroup[] = orderKinds(byKind, parsed.value.text).map((kind) => ({
      key: kind,
      label: groupLabel(kind),
      items: boostRecent(byKind.get(kind) ?? [], recentIds, parsed.value.text).slice(0, limit),
    }))
    return best.value
      ? [
          {
            key: 'best',
            label: groupLabel('best'),
            note: best.value.note,
            items: best.value.items,
          },
          ...ranked,
        ]
      : ranked
  })

  const flatResults = computed(() => groups.value.flatMap((group) => group.items))

  const hasMatches = computed(() => flatResults.value.some((result) => result.kind !== 'ask'))

  const execute = async (result: SearchResult, newTab = false) => {
    if (result.keepOpen) {
      await result.run?.()
      return
    }
    if (newTab && result.route) {
      window.open(router.resolve(result.route).href, '_blank', 'noopener')
      remember(result)
      return
    }
    remember(result)
    onClose()
    if (result.run) {
      await result.run()
    } else if (result.route) {
      await router.push(result.route)
    }
  }

  return {
    query,
    parsed,
    groups,
    flatResults,
    hasMatches,
    execute,
    remoteStatus: remote.status,
    semanticAvailable: remote.semanticAvailable,
    indexing: remote.indexing,
    aiStatus: ai.status,
    aiOutcome: computed(() => ai.result.value?.outcome ?? null),
  }
}
