import { computed, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
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
  type LiveVoiceArtifact,
  type LiveVoiceAudioSession,
  type LiveVoiceSocketHandlers,
} from '@/services/liveVoice/audio'
import { liveVoiceSocketUrl } from '@/services/liveVoice/socket-url'
import { openDirectVoice } from '@/services/liveVoice/webrtc'
import { voiceCompany } from '@/services/liveVoice/company'
import { liveCueForTransition, playLiveCue } from '@/services/liveVoice/cues'
import { nextLivePhase, type LivePhase } from '@/services/liveVoice/machine'
import {
  captionsPreferredRef,
  resolveLiveVoice,
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
import { useConfigStore } from '@/stores/config'
import { useHistoryStore } from '@/stores/history'
import { useIncognitoStore } from '@/stores/incognito'
import { useMediaJobsStore } from '@/stores/mediaJobs'
import { useUsageTaximeterStore } from '@/stores/usageTaximeter'

const CONNECT_MS = 15_000

/** The orb reads the level often. Faster than this only re-renders the chat. */
const LEVEL_MS = 100

export function useLiveVoice(onBudget: (budget: LiveVoiceCostBudget) => void) {
  const { t, locale } = useI18n()
  const { error: notifyError } = useNotification()
  const chats = useChatsStore()
  const history = useHistoryStore()
  const incognito = useIncognitoStore()
  const mediaJobs = useMediaJobsStore()
  const taximeter = useUsageTaximeterStore()

  const phase = ref<LivePhase>('idle')
  const options = ref<LiveVoiceOptions | null>(null)
  const consentOpen = ref(false)
  const reason = ref<LiveEndReason>('provider_error')
  const muted = ref(false)
  const captions = captionsPreferredRef()
  const level = ref(0)
  const outLevel = ref(0)
  const secondsLeft = ref<number | null>(null)
  const captionText = ref('')
  const activeModel = ref<LiveVoiceModel | null>(null)
  const budgetWarn = ref(false)
  const budgetWrap = ref(false)
  let audio: LiveVoiceAudioSession | null = null
  let mic: MediaStream | null = null
  let audioContext: AudioContext | null = null
  let captureAbort: AbortController | null = null
  let sessionId: number | null = null
  let sessionChatId: number | null = null
  let adoptingChat = false
  let connectTimer: ReturnType<typeof setTimeout> | undefined
  let starting = false
  let consentSaving = false
  let ignoreClose = false
  let lastLevelAt = 0
  let liveRun = 0
  const liveTurns: { user?: string; assistant?: string } = {}
  let spokenLocalIds = new Set<string>()

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
    publishSpokenIds()
  }

  function publishSpokenIds(): void {
    const chatId = sessionChatId
    const spoken = [...spokenLocalIds]
    if (chatId === null || spoken.length === 0) return
    void history.adoptSpokenIds(chatId, spoken)
  }

  function closeLiveTurns() {
    for (const role of ['user', 'assistant'] as const) {
      const id = liveTurns[role]
      if (!id) continue
      const message = history.messages.find((item) => item.id === id)
      const part = message?.parts.find((item) => item.type === 'text')
      const text = part?.content ?? ''
      history.finishStreamingMessage(id)
      delete liveTurns[role]
      if (text.trim() !== '') noteSavedTurn(role, text)
    }
  }

  function showTurn(role: 'user' | 'assistant', text: string, final: boolean) {
    captionText.value = text
    if (incognito.active || text.trim() === '') return
    const existing = liveTurns[role]
    if (existing) {
      spokenLocalIds.add(existing)
      history.updateStreamingMessage(existing, text)
      if (final) {
        history.finishStreamingMessage(existing)
        delete liveTurns[role]
        noteSavedTurn(role, text)
      }
      return
    }
    const id = history.addStreamingMessage(role)
    spokenLocalIds.add(id)
    history.updateStreamingMessage(id, text)
    if (final) {
      history.finishStreamingMessage(id)
      noteSavedTurn(role, text)
    } else {
      liveTurns[role] = id
    }
  }

  /** A stored row belongs to the call's chat; another open chat must not show it. */
  async function showArtifact(artifact: LiveVoiceArtifact): Promise<void> {
    if (artifact.messageId !== undefined && chats.activeChatId !== sessionChatId) return
    await history.insertSpokenArtifact(artifact)
    if (artifact.state === 'running' && !incognito.active) void mediaJobs.loadActive()
  }

  function noteSavedTurn(role: 'user' | 'assistant', text: string) {
    const chatId = sessionChatId ?? chats.activeChatId
    if (chatId === null || incognito.active) return
    const trimmed = text.trim()
    const preview = trimmed.length > 30 ? `${trimmed.slice(0, 30)}…` : trimmed
    chats.bumpChatActivity(chatId, {
      firstMessagePreview: role === 'user' && preview !== '' ? preview : undefined,
    })
  }

  async function adoptSpokenChat(chatId: number | null | undefined): Promise<void> {
    if (typeof chatId !== 'number') {
      sessionChatId = chats.activeChatId
      return
    }
    sessionChatId = chatId
    if (chatId === chats.activeChatId && chats.chats.some((chat) => chat.id === chatId)) return
    adoptingChat = true
    try {
      if (!chats.chats.some((chat) => chat.id === chatId)) await chats.loadChats()
      if (chats.activeChatId !== chatId) chats.setActiveChat(chatId)
    } finally {
      adoptingChat = false
    }
  }

  function refreshSpokenChat() {
    if (incognito.active || sessionChatId === null) return
    void chats.loadChats()
  }

  function liveRunIs(run: number): boolean {
    return run === liveRun
  }

  function stopStream(stream: MediaStream | null | undefined): void {
    stream?.getTracks().forEach((track) => track.stop())
  }

  function dropSession(id: number | null): void {
    if (id === null) return
    void endLiveVoiceSession(id, 'user_end').catch(() => undefined)
  }

  /** Stops the microphone and the socket, including a call that is still connecting. */
  function releaseCapture(): void {
    captureAbort?.abort()
    captureAbort = null
    stopStream(mic)
    mic = null
    const context = audioContext
    audioContext = null
    if (context && context.state !== 'closed') context.close().catch(() => undefined)
  }

  function teardown() {
    clearTimeout(connectTimer)
    closeLiveTurns()
    const current = audio
    audio = null
    current?.close()
    releaseCapture()
    level.value = 0
    outLevel.value = 0
    refreshSpokenChat()
  }

  async function allowConsent(): Promise<void> {
    if (consentSaving || starting) return
    consentSaving = true
    try {
      await setLiveVoiceConsent(true)
    } catch {
      notifyError(t('chatInput.liveVoice.consentFailed'))
      return
    } finally {
      consentSaving = false
    }
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
      await loadOptions()
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
      if (budget) onBudget(budget)
      else fail('limit_budget')
      return
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
    muted.value = false
    budgetWarn.value = false
    budgetWrap.value = false
    captionText.value = ''
    const run = ++liveRun
    spokenLocalIds = new Set()
    captureAbort?.abort()
    const controller = new AbortController()
    captureAbort = controller
    const context = new AudioContext()
    audioContext = context
    const resumed = context.resume()
    const micPromise = navigator.mediaDevices
      .getUserMedia({
        audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
      })
      .then((stream) => {
        if (!liveRunIs(run)) stopStream(stream)
        else mic = stream
        return stream
      })
    let openedSessionId: number | null = null
    apply({ type: 'start' })
    armConnectTimer(run, () => openedSessionId)
    try {
      await resumed
      if (!liveRunIs(run)) return
      await micPromise
      if (!liveRunIs(run) || mic === null) return
      const model = defaultModel()
      const listed = model?.voices ?? []
      const voice = model
        ? resolveLiveVoice(model.id ?? null, model.provider ?? '', listed, model.voice ?? '')
        : ''
      const session = await startLiveVoiceSession({
        chatId: chats.activeChatId,
        modelId: model?.id ?? null,
        voice: voice || undefined,
        language: String(locale.value || 'en'),
        client: 'web',
        incognito: incognito.active,
      })
      openedSessionId = session.sessionId
      if (!liveRunIs(run)) return
      await adoptSpokenChat(session.chatId)
      if (!liveRunIs(run)) return
      const inRate = session.audio?.inRate
      const outRate = session.audio?.outRate
      if (inRate === undefined || outRate === undefined) {
        throw new Error('audio')
      }
      sessionId = session.sessionId
      activeModel.value = model ?? null
      secondsLeft.value = session.allowedSeconds ?? null
      const handlers: LiveVoiceSocketHandlers = {
        onReady: () => {
          if (!liveRunIs(run)) return
          clearTimeout(connectTimer)
          apply({ type: 'ready' })
        },
        onState: (state) => {
          if (!liveRunIs(run)) return
          apply({ type: 'state', state })
        },
        onTranscript: (role, text, final) => {
          if (!liveRunIs(run)) return
          showTurn(role, text, final)
        },
        onStored: (role, messageId, text) => {
          if (!liveRunIs(run)) return
          history.claimSpokenMessage(role, messageId, text)
        },
        onReply: (details) => {
          if (!liveRunIs(run)) return
          history.attachSpokenReply(details)
        },
        onArtifact: (artifact) => {
          if (!liveRunIs(run)) return
          void showArtifact(artifact)
        },
        onPlaybackIdle: () => {
          if (!liveRunIs(run)) return
          apply({ type: 'audio_idle' })
        },
        onInterrupted: () => {
          if (!liveRunIs(run)) return
          outLevel.value = 0
          apply({ type: 'interrupted' })
        },
        onWrapUp: () => {
          if (!liveRunIs(run)) return
          budgetWrap.value = true
          muted.value = true
          audio?.setMuted(true)
          apply({ type: 'wrap_up' })
        },
        onEnded: (next) => {
          if (!liveRunIs(run)) return
          reason.value = liveEndReason(next)
          apply({ type: 'ended' })
          teardown()
          publishSpokenIds()
        },
        onUsage: (left) => {
          if (!liveRunIs(run)) return
          if (left !== null) secondsLeft.value = left
          void taximeter.loadSummary()
        },
        onWarn: (left) => {
          if (!liveRunIs(run)) return
          budgetWarn.value = true
          secondsLeft.value = left
        },
        onLevel: (value) => {
          if (!liveRunIs(run) || !levelDue()) return
          level.value = value
        },
        onOutLevel: (value) => {
          if (!liveRunIs(run) || !levelDue()) return
          outLevel.value = value
        },
        onAudioOut: () => {
          if (!liveRunIs(run)) return
          apply({ type: 'audio' })
        },
        onSocketClosed: () => {
          if (!liveRunIs(run) || ignoreClose || phase.value === 'ended' || phase.value === 'idle')
            return
          fail('network_lost')
        },
      }
      const wsUrl = liveVoiceSocketUrl(session.wsUrl, window.location, useConfigStore().apiBaseUrl)
      const opened =
        session.transport === 'webrtc'
          ? await openDirectVoice({ context, mic, wsUrl, handlers, signal: controller.signal })
          : await openLiveVoiceAudio({
              context,
              mic,
              wsUrl,
              inRate,
              outRate,
              handlers,
              signal: controller.signal,
            })
      if (!liveRunIs(run)) {
        opened.close()
        return
      }
      audio = opened
    } catch (error) {
      if (!liveRunIs(run)) return
      const hangUp = sessionId ?? openedSessionId
      sessionId = null
      stopStream(mic)
      if (audioContext && audioContext.state !== 'closed') {
        audioContext.close().catch(() => undefined)
      }
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
        else fail('limit_budget')
      } else if (error instanceof ApiError && error.status === 503) {
        fail('capacity')
      } else {
        fail('provider_error')
      }
      if (hangUp !== null && phase.value === 'ended') {
        void endLiveVoiceSession(hangUp, 'provider_error').catch(() => undefined)
      }
    } finally {
      if (!liveRunIs(run)) {
        if (sessionId === openedSessionId) sessionId = null
        dropSession(openedSessionId)
      }
      starting = false
    }
  }

  /** The whole handshake, including the microphone prompt, has a limit. */
  function armConnectTimer(run: number, sessionOf: () => number | null): void {
    clearTimeout(connectTimer)
    connectTimer = setTimeout(() => {
      if (!liveRunIs(run) || phase.value !== 'connecting') return
      const id = sessionOf()
      liveRun += 1
      captureAbort?.abort()
      fail('provider_error')
      if (id !== null) void endLiveVoiceSession(id, 'provider_error').catch(() => undefined)
    }, CONNECT_MS)
  }

  function levelDue(): boolean {
    const now = performance.now()
    if (now - lastLevelAt < LEVEL_MS) return false
    lastLevelAt = now
    return true
  }

  function returnToComposer(): void {
    phase.value = 'idle'
    sessionId = null
    sessionChatId = null
    budgetWarn.value = false
    budgetWrap.value = false
  }

  async function end() {
    liveRun += 1
    ignoreClose = true
    audio?.sendEnd()
    const id = sessionId
    teardown()
    publishSpokenIds()
    reason.value = 'user_end'
    returnToComposer()
    if (id !== null) {
      try {
        await endLiveVoiceSession(id, 'user_end')
      } catch {
        // The socket close still ends the row on the server.
      }
    }
  }

  function toggleMute() {
    muted.value = !muted.value
    audio?.setMuted(muted.value)
  }

  function toggleCaptions() {
    setCaptionsPreferred(!captions.value)
  }

  function dismiss() {
    liveRun += 1
    teardown()
    returnToComposer()
  }

  watch(phase, (next, previous) => {
    const cue = liveCueForTransition(previous, next, reason.value)
    if (cue) playLiveCue(cue)
  })

  watch(
    () => chats.activeChatId,
    (next, previous) => {
      if (
        !adoptingChat &&
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
    liveRun += 1
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
