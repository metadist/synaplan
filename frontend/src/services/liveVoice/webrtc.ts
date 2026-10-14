import {
  applyControlMessage,
  throwIfLiveVoiceAborted,
  type LiveVoiceAudioSession,
  type LiveVoiceSocketHandlers,
} from './audio'

/**
 * OpenAI audio goes peer-to-peer. The WebSocket only carries the offer,
 * the answer, captions, the budget and hang-up. The provider key stays
 * on the server.
 */
export async function openDirectVoice(input: {
  context: AudioContext
  mic: MediaStream
  wsUrl: string
  handlers: LiveVoiceSocketHandlers
  signal?: AbortSignal
}): Promise<LiveVoiceAudioSession> {
  throwIfLiveVoiceAborted(input.signal)
  const socket = new WebSocket(input.wsUrl)
  const peer = new RTCPeerConnection({ iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] })
  const remote = new Audio()
  remote.autoplay = true
  let closed = false
  let muted = false
  let levelTimer = 0

  const source = input.context.createMediaStreamSource(input.mic)
  const analyser = input.context.createAnalyser()
  analyser.fftSize = 256
  source.connect(analyser)
  const samples = new Uint8Array(analyser.fftSize)
  let remoteSource: MediaStreamAudioSourceNode | null = null
  let remoteAnalyser: AnalyserNode | null = null
  let lastOutLevelAt = 0
  const pumpLevel = () => {
    input.handlers.onLevel(rmsLevel(analyser, samples))
    const now = performance.now()
    if (remoteAnalyser && now - lastOutLevelAt >= 100) {
      lastOutLevelAt = now
      input.handlers.onOutLevel(rmsLevel(remoteAnalyser, samples))
    }
    levelTimer = window.requestAnimationFrame(pumpLevel)
  }
  pumpLevel()

  for (const track of input.mic.getTracks()) peer.addTrack(track, input.mic)
  peer.createDataChannel('oai-events')
  peer.onconnectionstatechange = () => {
    if (peer.connectionState === 'failed') input.handlers.onSocketClosed()
  }
  peer.ontrack = (event) => {
    const stream = event.streams[0] ?? new MediaStream([event.track])
    remote.srcObject = stream
    void remote.play().catch(() => undefined)
    remoteSource?.disconnect()
    remoteSource = input.context.createMediaStreamSource(stream)
    remoteAnalyser = input.context.createAnalyser()
    remoteAnalyser.fftSize = 256
    remoteSource.connect(remoteAnalyser)
    input.handlers.onAudioOut()
  }

  let stopped = false
  const stop = () => {
    if (stopped) return
    stopped = true
    closed = true
    window.cancelAnimationFrame(levelTimer)
    remoteSource?.disconnect()
    peer.close()
    remote.pause()
    remote.srcObject = null
    if (socket.readyState === WebSocket.OPEN || socket.readyState === WebSocket.CONNECTING) {
      socket.close()
    }
    input.mic.getTracks().forEach((track) => track.stop())
    source.disconnect()
    void input.context.close().catch(() => undefined)
  }

  input.signal?.addEventListener('abort', stop, { once: true })

  socket.onmessage = (event) => {
    if (typeof event.data !== 'string') return
    let message: { type?: string; sdp?: string }
    try {
      message = JSON.parse(event.data) as { type?: string; sdp?: string }
    } catch {
      return
    }
    if (message.type === 'offer_required') {
      void sendOffer().catch(() => input.handlers.onSocketClosed())
      return
    }
    if (message.type === 'answer' && message.sdp) {
      void peer
        .setRemoteDescription({ type: 'answer', sdp: message.sdp })
        .catch(() => input.handlers.onSocketClosed())
      return
    }
    applyControlMessage(event.data, input.handlers)
  }

  socket.onclose = () => {
    if (!closed) input.handlers.onSocketClosed()
  }

  try {
    await new Promise<void>((resolve, reject) => {
      if (input.signal?.aborted) {
        reject(new DOMException('The operation was aborted.', 'AbortError'))
        return
      }
      socket.onopen = () => resolve()
      socket.onerror = () => reject(new Error('socket'))
    })
    throwIfLiveVoiceAborted(input.signal)
  } catch (error) {
    input.signal?.removeEventListener('abort', stop)
    stop()
    throw error
  }

  async function sendOffer(): Promise<void> {
    const offer = await peer.createOffer()
    await peer.setLocalDescription(offer)
    await waitForIce(peer)
    if (socket.readyState !== WebSocket.OPEN || !peer.localDescription) return
    socket.send(JSON.stringify({ type: 'offer', sdp: peer.localDescription.sdp }))
  }

  return {
    setMuted(next: boolean) {
      muted = next
      input.mic.getTracks().forEach((track) => {
        track.enabled = !muted
      })
      if (socket.readyState === WebSocket.OPEN) {
        socket.send(JSON.stringify({ type: next ? 'mute' : 'unmute' }))
      }
    },
    sendEnd() {
      if (socket.readyState === WebSocket.OPEN) socket.send(JSON.stringify({ type: 'end' }))
    },
    close() {
      input.signal?.removeEventListener('abort', stop)
      stop()
    },
  }
}

function rmsLevel(analyser: AnalyserNode, samples: Uint8Array<ArrayBuffer>): number {
  analyser.getByteTimeDomainData(samples)
  let sum = 0
  for (const value of samples) {
    const centered = (value - 128) / 128
    sum += centered * centered
  }
  return Math.min(1, Math.sqrt(sum / samples.length) * 4)
}

function waitForIce(peer: RTCPeerConnection): Promise<void> {
  if (peer.iceGatheringState === 'complete') return Promise.resolve()
  return new Promise((resolve) => {
    const timer = window.setTimeout(done, 2500)
    function done() {
      window.clearTimeout(timer)
      peer.removeEventListener('icegatheringstatechange', onState)
      resolve()
    }
    function onState() {
      if (peer.iceGatheringState === 'complete') done()
    }
    peer.addEventListener('icegatheringstatechange', onState)
  })
}
