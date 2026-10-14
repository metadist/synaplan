import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { useLiveVoice } from '@/composables/useLiveVoice'

/**
 * Ending a live call before the socket exists must still stop the microphone.
 * Otherwise the composer comes back and the call keeps listening.
 */

const startLiveVoiceSession = vi.hoisted(() => vi.fn())
const endLiveVoiceSession = vi.hoisted(() => vi.fn())
const fetchLiveVoiceOptions = vi.hoisted(() => vi.fn())
const openLiveVoiceAudio = vi.hoisted(() => vi.fn())
const openDirectVoice = vi.hoisted(() => vi.fn())
const addStreamingMessage = vi.hoisted(() => vi.fn(() => 'bubble'))
const claimSpokenMessage = vi.hoisted(() => vi.fn())
const adoptSpokenIds = vi.hoisted(() => vi.fn())
const insertSpokenArtifact = vi.hoisted(() => vi.fn(() => Promise.resolve()))
const loadActiveJobs = vi.hoisted(() => vi.fn(() => Promise.resolve()))
const activeChat = vi.hoisted(() => ({ id: 7 }))

vi.mock('@/services/api/liveVoiceApi', () => ({
  startLiveVoiceSession,
  endLiveVoiceSession,
  fetchLiveVoiceOptions,
  isBudgetError: () => false,
  liveCostBudget: () => null,
  setLiveVoiceConsent: vi.fn(),
}))

vi.mock('@/services/liveVoice/audio', () => ({
  openLiveVoiceAudio,
}))

vi.mock('@/services/liveVoice/webrtc', () => ({
  openDirectVoice,
}))

vi.mock('@/stores/chats', () => ({
  useChatsStore: () => ({
    get activeChatId() {
      return activeChat.id
    },
    chats: [{ id: 7 }],
    loadChats: vi.fn(),
    setActiveChat: vi.fn(),
    bumpChatActivity: vi.fn(),
  }),
}))

vi.mock('@/stores/history', () => ({
  useHistoryStore: () => ({
    messages: [],
    finishStreamingMessage: vi.fn(),
    updateStreamingMessage: vi.fn(),
    addStreamingMessage,
    claimSpokenMessage,
    adoptSpokenIds,
    insertSpokenArtifact,
    attachSpokenReply: vi.fn(),
  }),
}))

vi.mock('@/stores/incognito', () => ({
  useIncognitoStore: () => ({ active: false }),
}))

vi.mock('@/stores/mediaJobs', () => ({
  useMediaJobsStore: () => ({ loadActive: loadActiveJobs }),
}))

vi.mock('@/stores/usageTaximeter', () => ({
  useUsageTaximeterStore: () => ({ loadSummary: vi.fn() }),
}))

const Harness = defineComponent({
  setup() {
    return useLiveVoice(() => undefined)
  },
  template: '<div />',
})

function deferred<T>() {
  let resolve: (value: T) => void = () => undefined
  const promise = new Promise<T>((done) => {
    resolve = done
  })
  return { promise, resolve }
}

function fakeTrack() {
  const track = {
    readyState: 'live' as 'live' | 'ended',
    stop: vi.fn(),
  }
  track.stop.mockImplementation(() => {
    track.readyState = 'ended'
  })
  return track
}

const session = {
  sessionId: 31,
  chatId: 7,
  wsUrl: 'ws://backend/voice/ws?ticket=one',
  transport: 'websocket',
  audio: { inRate: 24000, outRate: 24000 },
  allowedSeconds: 30,
}

beforeEach(() => {
  startLiveVoiceSession.mockReset()
  endLiveVoiceSession.mockReset()
  openLiveVoiceAudio.mockReset()
  openDirectVoice.mockReset()
  addStreamingMessage.mockClear()
  addStreamingMessage.mockImplementation(() => 'bubble')
  claimSpokenMessage.mockClear()
  adoptSpokenIds.mockClear()
  insertSpokenArtifact.mockClear()
  loadActiveJobs.mockClear()
  activeChat.id = 7
  endLiveVoiceSession.mockResolvedValue({})
  fetchLiveVoiceOptions.mockResolvedValue({
    available: true,
    consentGiven: true,
    budgetExhausted: false,
    models: [{ id: 4, provider: 'OpenAI', name: 'GPT Live' }],
    defaultModelId: 4,
    estimatedSecondsLeft: 120,
  })
  vi.stubGlobal(
    'AudioContext',
    class {
      state = 'running'
      resume() {
        return Promise.resolve()
      }
      close() {
        this.state = 'closed'
        return Promise.resolve()
      }
    }
  )
})

describe('useLiveVoice hang-up', () => {
  it('stops the microphone when the call is ended before the session exists', async () => {
    const track = fakeTrack()
    const mic = deferred<{ getTracks: () => (typeof track)[] }>()
    Object.defineProperty(navigator, 'mediaDevices', {
      configurable: true,
      value: { getUserMedia: vi.fn(() => mic.promise) },
    })
    const started = deferred<typeof session>()
    startLiveVoiceSession.mockReturnValue(started.promise)

    const wrapper = mount(Harness)
    const pending = wrapper.vm.press()
    mic.resolve({ getTracks: () => [track] })
    await flushPromises()
    expect(wrapper.vm.phase).toBe('connecting')
    expect(startLiveVoiceSession).toHaveBeenCalledOnce()
    expect(track.stop).not.toHaveBeenCalled()

    const ending = wrapper.vm.end()
    await flushPromises()
    expect(track.stop).toHaveBeenCalled()
    expect(track.readyState).toBe('ended')

    started.resolve(session)
    await pending
    await ending
    await flushPromises()

    expect(openLiveVoiceAudio).not.toHaveBeenCalled()
    expect(endLiveVoiceSession).toHaveBeenCalledWith(31, 'user_end')
    expect(wrapper.vm.phase).toBe('idle')
    expect(wrapper.vm.active).toBe(false)
    expect(openLiveVoiceAudio).not.toHaveBeenCalled()
  })

  it('closes a socket that opens after End and ignores speech once the composer is back', async () => {
    const track = fakeTrack()
    Object.defineProperty(navigator, 'mediaDevices', {
      configurable: true,
      value: { getUserMedia: vi.fn().mockResolvedValue({ getTracks: () => [track] }) },
    })
    startLiveVoiceSession.mockResolvedValue(session)
    const opened = deferred<{ close: () => void; sendEnd: () => void; setMuted: () => void }>()
    const close = vi.fn()
    openLiveVoiceAudio.mockImplementation(
      (input: {
        signal?: AbortSignal
        handlers: { onTranscript: (role: string, text: string, final: boolean) => void }
      }) => {
        input.signal?.addEventListener('abort', () => close())
        return opened.promise.then((audio) => {
          input.handlers.onTranscript('user', 'still listening', true)
          return audio
        })
      }
    )

    const wrapper = mount(Harness)
    const pending = wrapper.vm.press()
    await flushPromises()
    expect(openLiveVoiceAudio).toHaveBeenCalledOnce()
    expect(wrapper.vm.phase).toBe('connecting')

    await wrapper.vm.end()
    expect(wrapper.vm.phase).toBe('idle')
    expect(wrapper.vm.active).toBe(false)
    expect(openLiveVoiceAudio.mock.calls[0]?.[0].signal.aborted).toBe(true)
    expect(close).toHaveBeenCalled()
    expect(track.readyState).toBe('ended')

    opened.resolve({ close, sendEnd: vi.fn(), setMuted: vi.fn() })
    await pending
    await flushPromises()

    expect(wrapper.vm.phase).toBe('idle')
    expect(wrapper.vm.active).toBe(false)
    expect(addStreamingMessage).not.toHaveBeenCalled()
    expect(close).toHaveBeenCalled()
  })

  it('ends a connected call by closing the microphone and the server session', async () => {
    const track = fakeTrack()
    Object.defineProperty(navigator, 'mediaDevices', {
      configurable: true,
      value: { getUserMedia: vi.fn().mockResolvedValue({ getTracks: () => [track] }) },
    })
    startLiveVoiceSession.mockResolvedValue(session)
    const close = vi.fn()
    const sendEnd = vi.fn()
    openLiveVoiceAudio.mockResolvedValue({ close, sendEnd, setMuted: vi.fn() })

    const wrapper = mount(Harness)
    await wrapper.vm.press()
    await flushPromises()
    expect(wrapper.vm.phase).toBe('connecting')

    await wrapper.vm.end()
    expect(sendEnd).toHaveBeenCalledOnce()
    expect(close).toHaveBeenCalledOnce()
    expect(track.readyState).toBe('ended')
    expect(endLiveVoiceSession).toHaveBeenCalledWith(31, 'user_end')
    expect(wrapper.vm.phase).toBe('idle')
    expect(wrapper.vm.active).toBe(false)
  })

  it('attaches the saved message id so the bubble actions can show', async () => {
    const track = fakeTrack()
    Object.defineProperty(navigator, 'mediaDevices', {
      configurable: true,
      value: { getUserMedia: vi.fn().mockResolvedValue({ getTracks: () => [track] }) },
    })
    startLiveVoiceSession.mockResolvedValue(session)
    let nextBubble = 0
    addStreamingMessage.mockImplementation(() => `bubble-${++nextBubble}`)
    let handlers: {
      onReady: () => void
      onTranscript: (role: 'user' | 'assistant', text: string, final: boolean) => void
      onStored: (role: 'user' | 'assistant', messageId: number, text: string) => void
    }
    openLiveVoiceAudio.mockImplementation(
      async (input: {
        handlers: {
          onReady: () => void
          onTranscript: (role: 'user' | 'assistant', text: string, final: boolean) => void
          onStored: (role: 'user' | 'assistant', messageId: number, text: string) => void
        }
      }) => {
        handlers = input.handlers
        return { close: vi.fn(), sendEnd: vi.fn(), setMuted: vi.fn() }
      }
    )

    const wrapper = mount(Harness)
    await wrapper.vm.press()
    await flushPromises()
    handlers!.onReady()
    handlers!.onTranscript('user', 'Hello', true)
    handlers!.onStored('user', 41, 'Hello')
    handlers!.onTranscript('assistant', 'Hi there', true)
    handlers!.onStored('assistant', 42, 'Hi there')

    expect(claimSpokenMessage).toHaveBeenCalledWith('user', 41, 'Hello')
    expect(claimSpokenMessage).toHaveBeenCalledWith('assistant', 42, 'Hi there')

    await wrapper.vm.end()
    expect(adoptSpokenIds).toHaveBeenCalledWith(7, ['bubble-1', 'bubble-2'])
    expect(wrapper.vm.phase).toBe('idle')
  })
})

describe('useLiveVoice made media', () => {
  async function connect() {
    const track = fakeTrack()
    Object.defineProperty(navigator, 'mediaDevices', {
      configurable: true,
      value: { getUserMedia: vi.fn().mockResolvedValue({ getTracks: () => [track] }) },
    })
    startLiveVoiceSession.mockResolvedValue(session)
    let handlers: { onReady: () => void; onArtifact: (artifact: unknown) => void }
    openLiveVoiceAudio.mockImplementation(async (input: { handlers: typeof handlers }) => {
      handlers = input.handlers
      return { close: vi.fn(), sendEnd: vi.fn(), setMuted: vi.fn() }
    })
    const wrapper = mount(Harness)
    await wrapper.vm.press()
    await flushPromises()
    handlers!.onReady()
    return { wrapper, handlers: handlers! }
  }

  it('shows a picture that is still rendering in the call chat and in the jobs tray', async () => {
    const { wrapper, handlers } = await connect()
    const artifact = { type: 'image', state: 'running', messageId: 55 }

    handlers.onArtifact(artifact)
    await flushPromises()

    expect(insertSpokenArtifact).toHaveBeenCalledWith(artifact)
    expect(loadActiveJobs).toHaveBeenCalledOnce()
    await wrapper.vm.end()
  })

  it('keeps a stored picture out of another chat the person opened during the call', async () => {
    const { wrapper, handlers } = await connect()
    activeChat.id = 8

    handlers.onArtifact({ type: 'image', state: 'completed', messageId: 55 })
    await flushPromises()

    expect(insertSpokenArtifact).not.toHaveBeenCalled()
    await wrapper.vm.end()
  })
})
