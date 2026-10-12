export interface LiveVoiceSocketHandlers {
  onReady: () => void
  onState: (state: 'thinking' | 'listening' | 'speaking') => void
  onTranscript: (role: 'user' | 'assistant', text: string, final: boolean) => void
  onPlaybackIdle: () => void
  onInterrupted: () => void
  onWrapUp: () => void
  onEnded: (reason: string) => void
  onUsage: (secondsLeft: number | null) => void
  onWarn: (secondsLeft: number) => void
  onLevel: (level: number) => void
  onAudioOut: () => void
  onSocketClosed: () => void
}

export interface LiveVoiceAudioSession {
  setMuted: (muted: boolean) => void
  sendEnd: () => void
  close: () => void
}

/**
 * Microphone to PCM16, and provider PCM16 back to the speakers.
 * The AudioContext and getUserMedia call must be created in the click turn.
 */
export async function openLiveVoiceAudio(input: {
  context: AudioContext
  mic: MediaStream
  wsUrl: string
  inRate: number
  outRate: number
  handlers: LiveVoiceSocketHandlers
}): Promise<LiveVoiceAudioSession> {
  await input.context.audioWorklet.addModule(new URL('./pcm-capture.worklet.js', import.meta.url))
  await input.context.audioWorklet.addModule(new URL('./pcm-playback.worklet.js', import.meta.url))

  const source = input.context.createMediaStreamSource(input.mic)
  const capture = new AudioWorkletNode(input.context, 'pcm-capture')
  capture.port.postMessage({ type: 'config', targetRate: input.inRate })
  const silent = input.context.createGain()
  silent.gain.value = 0
  source.connect(capture)
  capture.connect(silent)
  silent.connect(input.context.destination)

  const playback = new AudioWorkletNode(input.context, 'pcm-playback')
  playback.port.postMessage({ type: 'config', sourceRate: input.outRate })
  playback.port.onmessage = (event: MessageEvent<{ type?: string }>) => {
    if (event.data?.type === 'idle') input.handlers.onPlaybackIdle()
  }
  playback.connect(input.context.destination)

  const socket = new WebSocket(input.wsUrl)
  socket.binaryType = 'arraybuffer'
  let muted = false
  let closed = false

  const stopTracks = () => {
    input.mic.getTracks().forEach((track) => track.stop())
    capture.disconnect()
    playback.disconnect()
    source.disconnect()
    void input.context.close()
  }

  capture.port.onmessage = (
    event: MessageEvent<{ type: string; pcm?: Int16Array; level?: number }>
  ) => {
    if (event.data.type !== 'pcm' || !event.data.pcm) return
    if (typeof event.data.level === 'number') input.handlers.onLevel(event.data.level)
    if (muted || socket.readyState !== WebSocket.OPEN) return
    socket.send(pcm16Frame(event.data.pcm))
  }

  socket.onmessage = (event) => {
    if (typeof event.data !== 'string') {
      if (input.context.state === 'suspended') void input.context.resume()
      const pcm = new Int16Array(event.data as ArrayBuffer)
      playback.port.postMessage({ type: 'pcm', pcm }, [pcm.buffer])
      input.handlers.onAudioOut()
      return
    }
    applyControlMessage(event.data, input.handlers, () => {
      playback.port.postMessage({ type: 'flush' })
    })
  }

  socket.onclose = () => {
    if (!closed) input.handlers.onSocketClosed()
  }

  await new Promise<void>((resolve, reject) => {
    socket.onopen = () => resolve()
    socket.onerror = () => reject(new Error('socket'))
  })

  return {
    setMuted(next: boolean) {
      muted = next
      if (socket.readyState === WebSocket.OPEN) {
        socket.send(JSON.stringify({ type: next ? 'mute' : 'unmute' }))
      }
    },
    sendEnd() {
      if (socket.readyState === WebSocket.OPEN) socket.send(JSON.stringify({ type: 'end' }))
    },
    close() {
      closed = true
      if (socket.readyState === WebSocket.OPEN || socket.readyState === WebSocket.CONNECTING) {
        socket.close()
      }
      stopTracks()
    },
  }
}

/** Little-endian PCM16 bytes. A typed-array copy would keep only the low byte of each sample. */
export function pcm16Frame(pcm: Int16Array): Uint8Array {
  return new Uint8Array(pcm.buffer, pcm.byteOffset, pcm.byteLength).slice()
}

export function applyControlMessage(
  raw: string,
  handlers: LiveVoiceSocketHandlers,
  onInterrupted?: () => void
): void {
  let message: {
    type?: string
    state?: string
    role?: string
    text?: string
    reason?: string
    secondsLeft?: number
    final?: boolean
  }
  try {
    message = JSON.parse(raw) as typeof message
  } catch {
    return
  }
  const state = message.state
  const role = message.role
  if (message.type === 'ready') handlers.onReady()
  if (
    message.type === 'state' &&
    (state === 'thinking' || state === 'listening' || state === 'speaking')
  ) {
    handlers.onState(state)
  }
  if (message.type === 'transcript' && (role === 'user' || role === 'assistant') && message.text) {
    handlers.onTranscript(role, message.text, message.final === true)
  }
  if (message.type === 'interrupted') {
    onInterrupted?.()
    handlers.onInterrupted()
  }
  if (message.type === 'wrap_up') handlers.onWrapUp()
  if (message.type === 'ended') handlers.onEnded(message.reason ?? 'provider_error')
  if (message.type === 'usage') {
    handlers.onUsage(typeof message.secondsLeft === 'number' ? message.secondsLeft : null)
  }
  if (message.type === 'warn' && typeof message.secondsLeft === 'number') {
    handlers.onWarn(message.secondsLeft)
  }
}
