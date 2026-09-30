import { computed, ref, watch, type Ref } from 'vue'
import { useRouter } from 'vue-router'
import { ChatBubbleLeftEllipsisIcon, ClockIcon } from '@heroicons/vue/24/outline'
import { i18n } from '@/i18n/instance'
import { LocalSearchIndex } from './localIndex'
import { ensureAllLocales } from './localeTexts'
import { usePageSources, type LocalEntry } from './pageSources'
import { useCommandSources } from './commandSources'
import { useSearchRecents } from './useSearchRecents'
import type { SearchGroup, SearchKind, SearchResult } from './types'

export const GROUP_ORDER: SearchKind[] = [
  'best',
  'command',
  'page',
  'setting',
  'chat',
  'file',
  'memory',
  'widget',
  'assistant',
  'task',
  'ask',
]

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
    for (const result of [...localResults.value, ...(askResult.value ? [askResult.value] : [])]) {
      const list = byKind.get(result.kind) ?? []
      if (!list.some((existing) => existing.id === result.id)) list.push(result)
      byKind.set(result.kind, list)
    }
    const limit = parsed.value.scope === 'all' ? MAX_PER_GROUP : MAX_SCOPED
    return GROUP_ORDER.filter((kind) => byKind.has(kind)).map((kind) => ({
      key: kind,
      label: groupLabel(kind),
      items: (byKind.get(kind) ?? []).slice(0, limit),
    }))
  })

  const flatResults = computed(() => groups.value.flatMap((group) => group.items))

  const hasMatches = computed(() => flatResults.value.some((result) => result.kind !== 'ask'))

  const execute = async (result: SearchResult, newTab = false) => {
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

  return { query, parsed, groups, flatResults, hasMatches, execute }
}
