import { computed, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ApiError } from '@/services/api/httpClient'
import {
  endLiveVoiceSession,
  fetchLiveVoiceOptions,
  isBudgetError,
  liveCostBudget,
  setLiveVoiceConsent,
  startLiveVoiceSession,
  type LiveVoiceCostBudget,
  type LiveVoiceModel,
  type LiveVoiceOptions,
} from '@/services/api/liveVoiceApi'
import {
  openLiveVoiceAudio,
  type LiveVoiceAudioSession,
  type LiveVoiceSocketHandlers,
} from '@/services/liveVoice/audio'
import { liveVoiceSocketUrl } from '@/services/liveVoice/socket-url'
import { openDirectVoice } from '@/services/liveVoice/webrtc'
import { voiceCompany } from '@/services/liveVoice/company'
import { nextLivePhase, type LivePhase } from '@/services/liveVoice/machine'
import {
  captionsPreferred,
  preferredVoice,
  setCaptionsPreferred,
} from '@/services/liveVoice/preferences'
import {
  liveEndReason,
  liveTerminalAction,
  liveTerminalActionKey,
  liveTerminalMessageKey,
  type LiveEndReason,
  type LiveTerminalAction,
} from '@/services/liveVoice/terminal'
import { useChatsStore } from '@/stores/chats'
import { useHistoryStore } from '@/stores/history'
import { useIncognitoStore } from '@/stores/incognito'
import { useUsageTaximeterStore } from '@/stores/usageTaximeter'

const CONNECT_MS = 15_000

export function useLiveVoice(onBudget: (budget: LiveVoiceCostBudget) => void) {
  const { locale } = useI18n()
  const chats = useChatsStore()
  const history = useHistoryStore()
  const incognito = useIncognitoStore()
  const taximeter = useUsageTaximeterStore()

  const phase = ref<LivePhase>('idle')
  const options = ref<LiveVoiceOptions | null>(null)
  const consentOpen = ref(false)
  const reason = ref<LiveEndReason>('provider_error')
  const muted = ref(false)
  const captions = ref(captionsPreferred())
  const level = ref(0)
  const outLevel = ref(0)
  const secondsLeft = ref<number | null>(null)
  const captionText = ref('')
  const activeModel = ref<LiveVoiceModel | null>(null)
  const budgetWarn = ref(false)
  const budgetWrap = ref(false)
  let audio: LiveVoiceAudioSession | null = null
  let sessionId: number | null = null
  let connectTimer: ReturnType<typeof setTimeout> | undefined
  let starting = false
  let ignoreClose = false
  const liveTurns: { user?: string; assistant?: string } = {}

  const active = computed(() => phase.value !== 'idle')
  const company = computed(() =>
    voiceCompany(activeModel.value?.provider ?? defaultModel()?.provider ?? '')
  )
  const terminalAction = computed<LiveTerminalAction>(() => liveTerminalAction(reason.value))

  function defaultModel(): LiveVoiceModel | undefined {
    const models = options.value?.models ?? []
    return models.find((model) => model.id === options.value?.defaultModelId) ?? models[0]
  }

  function apply(event: Parameters<typeof nextLivePhase>[1]) {
    phase.value = nextLivePhase(phase.value, event)
  }

  async function loadOptions(): Promise<void> {
    options.value = await fetchLiveVoiceOptions()
    secondsLeft.value = options.value.estimatedSecondsLeft ?? null
    activeModel.value = defaultModel() ?? null
  }

  function fail(next: LiveEndReason) {
    reason.value = next
    apply({ type: 'fail' })
    teardown()
  }

  function closeLiveTurns() {
    for (const role of ['user', 'assistant'] as const) {
      const id = liveTurns[role]
      if (!id) continue
      history.finishStreamingMessage(id)
      delete liveTurns[role]
    }
  }

  function showTurn(role: 'user' | 'assistant', text: string, final: boolean) {
    captionText.value = text
    if (incognito.active || text.trim() === '') return
    const existing = liveTurns[role]
    if (existing) {
      history.updateStreamingMessage(existing, text)
      if (final) {
        history.finishStreamingMessage(existing)
        delete liveTurns[role]
      }
      return
    }
    const id = history.addStreamingMessage(role)
    history.updateStreamingMessage(id, text)
    if (final) history.finishStreamingMessage(id)
    else liveTurns[role] = id
  }

  function teardown() {
    clearTimeout(connectTimer)
    closeLiveTurns()
    audio?.close()
    audio = null
    level.value = 0
    outLevel.value = 0
  }

  async function allowConsent(): Promise<void> {
    await setLiveVoiceConsent(true)
    if (options.value) options.value = { ...options.value, consentGiven: true }
    consentOpen.value = false
    await begin()
  }

  function declineConsent() {
    consentOpen.value = false
  }

  async function press(): Promise<void> {
    if (starting || phase.value !== 'idle') return
    try {
      if (!options.value) await loadOptions()
    } catch {
      fail('provider_error')
      return
    }
    const current = options.value
    if (!current?.available) {
      fail(current?.reason === 'not_configured' ? 'admin_disabled' : 'provider_error')
      return
    }
    if (current.budgetExhausted) {
      const budget = liveCostBudget(current.costBudget)
      if (budget) {
        onBudget(budget)
        return
      }
    }
    if (!current.consentGiven) {
      consentOpen.value = true
      return
    }
    await begin()
  }

  async function begin(): Promise<void> {
    if (starting) return
    starting = true
    ignoreClose = false
    budgetWarn.value = false
    budgetWrap.value = false
    captionText.value = ''
    const context = new AudioContext()
    const resumed = context.resume()
    const micPromise = navigator.mediaDevices.getUserMedia({
      audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
    })
    let mic: MediaStream | null = null
    apply({ type: 'start' })
    try {
      await resumed
      mic = await micPromise
      const model = defaultModel()
      const voice = model ? preferredVoice(model.provider ?? '') : ''
      const session = await startLiveVoiceSession({
        chatId: chats.activeChatId,
        modelId: model?.id ?? null,
        voice: voice || undefined,
        language: String(locale.value || 'en'),
        client: 'web',
        incognito: incognito.active,
      })
      const inRate = session.audio?.inRate
      const outRate = session.audio?.outRate
      if (inRate === undefined || outRate === undefined) {
        throw new Error('audio')
      }
      sessionId = session.sessionId
      activeModel.value = model ?? null
      secondsLeft.value = session.allowedSeconds ?? null
      const handlers: LiveVoiceSocketHandlers = {
        onReady: () => apply({ type: 'ready' }),
        onState: (state) => apply({ type: 'state', state }),
        onTranscript: (role, text, final) => showTurn(role, text, final),
        onPlaybackIdle: () => apply({ type: 'audio_idle' }),
        onInterrupted: () => {
          outLevel.value = 0
          apply({ type: 'interrupted' })
        },
        onWrapUp: () => {
          budgetWrap.value = true
          muted.value = true
          audio?.setMuted(true)
          apply({ type: 'wrap_up' })
        },
        onEnded: (next) => {
          reason.value = liveEndReason(next)
          apply({ type: 'ended' })
          teardown()
        },
        onUsage: (left) => {
          if (left !== null) secondsLeft.value = left
          void taximeter.loadSummary()
        },
        onWarn: (left) => {
          budgetWarn.value = true
          secondsLeft.value = left
        },
        onLevel: (value) => {
          level.value = value
        },
        onOutLevel: (value) => {
          outLevel.value = value
        },
        onAudioOut: () => apply({ type: 'audio' }),
        onSocketClosed: () => {
          if (ignoreClose || phase.value === 'ended' || phase.value === 'idle') return
          fail('network_lost')
        },
      }
      const wsUrl = liveVoiceSocketUrl(session.wsUrl, window.location)
      audio =
        session.transport === 'webrtc'
          ? await openDirectVoice({ context, mic, wsUrl, handlers })
          : await openLiveVoiceAudio({
              context,
              mic,
              wsUrl,
              inRate,
              outRate,
              handlers,
            })
      connectTimer = setTimeout(() => {
        if (phase.value === 'connecting') fail('provider_error')
      }, CONNECT_MS)
    } catch (error) {
      mic?.getTracks().forEach((track) => track.stop())
      context.close().catch(() => undefined)
      if (
        error instanceof DOMException &&
        (error.name === 'NotAllowedError' || error.name === 'NotFoundError')
      ) {
        fail('mic_denied')
      } else if (isBudgetError(error)) {
        const budget = liveCostBudget(options.value?.costBudget)
        phase.value = 'idle'
        teardown()
        if (budget) onBudget(budget)
      } else if (error instanceof ApiError && error.status === 503) {
        fail('capacity')
      } else {
        fail('provider_error')
      }
      if (sessionId !== null && phase.value === 'ended') {
        void endLiveVoiceSession(sessionId, 'provider_error').catch(() => undefined)
      }
    } finally {
      starting = false
    }
  }

  async function end() {
    if (phase.value === 'idle' || phase.value === 'ended') {
      phase.value = 'idle'
      return
    }
    ignoreClose = true
    apply({ type: 'wrap_up' })
    audio?.sendEnd()
    const id = sessionId
    teardown()
    if (id !== null) {
      try {
        await endLiveVoiceSession(id, 'user_end')
      } catch {
        // The socket close still ends the row on the server.
      }
    }
    reason.value = 'user_end'
    apply({ type: 'ended' })
  }

  function toggleMute() {
    muted.value = !muted.value
    audio?.setMuted(muted.value)
  }

  function toggleCaptions() {
    captions.value = !captions.value
    setCaptionsPreferred(captions.value)
  }

  function dismiss() {
    phase.value = 'idle'
    sessionId = null
    budgetWarn.value = false
    budgetWrap.value = false
  }

  watch(
    () => chats.activeChatId,
    (next, previous) => {
      if (
        previous !== undefined &&
        next !== previous &&
        phase.value !== 'idle' &&
        phase.value !== 'ended'
      ) {
        void end()
      }
    }
  )

  onUnmounted(() => {
    if (phase.value !== 'idle' && phase.value !== 'ended') void end()
    else teardown()
  })

  return {
    phase,
    active,
    options,
    consentOpen,
    reason,
    muted,
    captions,
    level,
    outLevel,
    secondsLeft,
    captionText,
    activeModel,
    budgetWarn,
    budgetWrap,
    company,
    terminalAction,
    terminalMessageKey: computed(() => liveTerminalMessageKey(reason.value)),
    terminalActionKey: computed(() => liveTerminalActionKey(terminalAction.value)),
    loadOptions,
    press,
    allowConsent,
    declineConsent,
    end,
    toggleMute,
    toggleCaptions,
    dismiss,
  }
}
