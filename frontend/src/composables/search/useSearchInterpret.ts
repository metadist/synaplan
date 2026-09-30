import { onScopeDispose, ref, watch, type Ref } from 'vue'
import { i18n } from '@/i18n/instance'
import { ApiError, getConfigSync } from '@/services/api/httpClient'
import {
  interpretSearch,
  type SmartSearchInterpretation,
  type SmartSearchInterpretCandidate,
  type SmartSearchInterpretRequest,
} from '@/services/api/searchApi'
import type { RemoteStatus } from './useRemoteSearch'
import type { SearchResult } from './types'

export type AiStatus = 'idle' | 'loading' | 'ready' | 'failed' | 'rateLimited'

/** Below this a query reads as keywords; the AI is only asked on request. */
const QUESTION_MIN_WORDS = 4
const MAX_CANDIDATES = 30
const CANDIDATE_KINDS: ReadonlySet<string> = new Set<SmartSearchInterpretCandidate['kind']>([
  'command',
  'page',
  'setting',
  'chat',
  'file',
  'memory',
  'widget',
  'assistant',
  'task',
])
const LANGUAGES: ReadonlySet<string> = new Set(['de', 'en', 'es', 'fr', 'tr'])

export function looksLikeQuestion(text: string): boolean {
  const trimmed = text.trim()
  if (trimmed === '') return false
  return trimmed.includes('?') || trimmed.split(/\s+/).length >= QUESTION_MIN_WORDS
}

export function toCandidate(result: SearchResult): SmartSearchInterpretCandidate | null {
  if (!CANDIDATE_KINDS.has(result.kind)) return null
  return {
    id: result.id,
    kind: result.kind as SmartSearchInterpretCandidate['kind'],
    title: result.title,
    subtitle: result.subtitle ?? null,
    value: result.setting?.current ?? null,
  }
}

const answerLanguage = (): SmartSearchInterpretRequest['language'] => {
  const locale = String(i18n.global.locale.value)
  return (LANGUAGES.has(locale) ? locale : 'en') as SmartSearchInterpretRequest['language']
}

/**
 * The AI tier of the palette. It runs once per question, after the server
 * results are in, so the model can only point at what the list shows. A new
 * keystroke aborts the call in flight; nothing here changes anything.
 */
export function useSearchInterpret(state: {
  text: Ref<string>
  candidates: Ref<SearchResult[]>
  remoteStatus: Ref<RemoteStatus>
  isOpen: Ref<boolean>
}) {
  const enabled = ref(false)
  const status = ref<AiStatus>('idle')
  const result = ref<SmartSearchInterpretation | null>(null)
  let askedFor = ''
  let controller: AbortController | null = null

  const clear = () => {
    controller?.abort()
    controller = null
    status.value = 'idle'
    result.value = null
    askedFor = ''
  }

  const ask = async () => {
    const text = state.text.value
    const candidates = state.candidates.value
      .map(toCandidate)
      .filter((candidate): candidate is SmartSearchInterpretCandidate => candidate !== null)
      .slice(0, MAX_CANDIDATES)
    if (!enabled.value || text === '' || candidates.length === 0) return

    controller?.abort()
    const own = new AbortController()
    controller = own
    askedFor = text
    status.value = 'loading'
    result.value = null
    try {
      const response = await interpretSearch(
        { q: text, language: answerLanguage(), candidates },
        own.signal
      )
      if (own.signal.aborted) return
      result.value = response
      status.value = response.outcome === 'failed' ? 'failed' : 'ready'
    } catch (error) {
      if (own.signal.aborted) return
      status.value = error instanceof ApiError && error.status === 429 ? 'rateLimited' : 'failed'
    } finally {
      if (controller === own) controller = null
    }
  }

  watch(
    state.isOpen,
    (open) => {
      enabled.value = open && getConfigSync().features?.smartSearchAi === true
      if (!open) clear()
    },
    { immediate: true }
  )

  watch(state.text, (text) => {
    if (text !== askedFor) clear()
  })

  watch([state.remoteStatus, state.text], ([remote, text]) => {
    if (remote !== 'ready' && remote !== 'error') return
    if (!enabled.value || text === askedFor || !looksLikeQuestion(text)) return
    void ask()
  })

  onScopeDispose(clear)

  return { enabled, status, result, ask }
}
