import {
  applyControlMessage,
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
}): Promise<LiveVoiceAudioSession> {
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
  const pumpLevel = () => {
    analyser.getByteTimeDomainData(samples)
    let sum = 0
    for (const value of samples) {
      const centered = (value - 128) / 128
      sum += centered * centered
    }
    input.handlers.onLevel(Math.min(1, Math.sqrt(sum / samples.length) * 4))
    levelTimer = window.requestAnimationFrame(pumpLevel)
  }
  pumpLevel()

  for (const track of input.mic.getTracks()) peer.addTrack(track, input.mic)
  peer.createDataChannel('oai-events')
  peer.ontrack = (event) => {
    remote.srcObject = event.streams[0] ?? new MediaStream([event.track])
    void remote.play().catch(() => undefined)
    input.handlers.onAudioOut()
  }

  const stop = () => {
    window.cancelAnimationFrame(levelTimer)
    peer.close()
    remote.pause()
    remote.srcObject = null
    input.mic.getTracks().forEach((track) => track.stop())
    source.disconnect()
    void input.context.close()
  }

  socket.onmessage = (event) => {
    if (typeof event.data !== 'string') return
    let message: { type?: string; sdp?: string }
    try {
      message = JSON.parse(event.data) as { type?: string; sdp?: string }
    } catch {
      return
    }
    if (message.type === 'offer_required') {
      void sendOffer()
      return
    }
    if (message.type === 'answer' && message.sdp) {
      void peer.setRemoteDescription({ type: 'answer', sdp: message.sdp })
      return
    }
    applyControlMessage(event.data, input.handlers)
  }

  socket.onclose = () => {
    if (!closed) input.handlers.onSocketClosed()
  }

  await new Promise<void>((resolve, reject) => {
    socket.onopen = () => resolve()
    socket.onerror = () => reject(new Error('socket'))
  })

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
      closed = true
      if (socket.readyState === WebSocket.OPEN || socket.readyState === WebSocket.CONNECTING) {
        socket.close()
      }
      stop()
    },
  }
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
