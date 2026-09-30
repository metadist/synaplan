import { computed, type Ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { RemoteStatus } from './useRemoteSearch'
import type { AiStatus } from './useSearchInterpret'
import type { SearchScope } from './useSmartSearch'

/**
 * The one-sentence lines above the results: what to type, "nothing found",
 * and how the server tiers answered (U8 — never a silent gap).
 */
export function usePaletteStatus(state: {
  parsed: Ref<{ scope: SearchScope; text: string }>
  hasMatches: Ref<boolean>
  remoteStatus: Ref<RemoteStatus>
  semanticAvailable: Ref<boolean>
  indexing: Ref<boolean>
  aiStatus: Ref<AiStatus>
  aiOutcome: Ref<string | null>
}) {
  const { t } = useI18n()

  const statusText = computed(() => {
    const { scope, text } = state.parsed.value
    if (text === '' && scope === 'all') return t('search.palette.emptyHint')
    if (text !== '' && !state.hasMatches.value) {
      if (state.remoteStatus.value === 'loading') return t('search.palette.remote.searching')
      return t('search.palette.noResults', { query: text })
    }
    return ''
  })

  const remoteNote = computed(() => {
    switch (state.remoteStatus.value) {
      case 'error':
        return t('search.palette.remote.unavailable')
      case 'rateLimited':
        return t('search.palette.remote.rateLimited')
      case 'ready':
        if (state.indexing.value) return t('search.palette.remote.indexing')
        if (!state.semanticAvailable.value) return t('search.palette.remote.keywordOnly')
        return ''
      default:
        return ''
    }
  })

  const aiNote = computed(() => {
    switch (state.aiStatus.value) {
      case 'loading':
        return t('search.palette.ai.thinking')
      case 'failed':
        return t('search.palette.ai.failed')
      case 'rateLimited':
        return t('search.palette.ai.rateLimited')
      case 'ready':
        return state.aiOutcome.value === 'no_match' ? t('search.palette.ai.noMatch') : ''
      default:
        return ''
    }
  })

  return { statusText, remoteNote, aiNote }
}
