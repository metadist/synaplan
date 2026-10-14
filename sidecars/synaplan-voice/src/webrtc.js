import { realtimeSessionPayload } from './adapters/realtime.js'

/**
 * Server-side SDP exchange. The browser never sees the provider key.
 * GPT-Live forbids every client event on the untrusted data channel.
 * GPT-Realtime has no equivalent switch; the app still does not open
 * that channel for control. Audio is Opus on the peer connection.
 */
export function liveCreateBody(config, sdp) {
  return {
    session: {
      model: config.model,
      store: false,
      instructions: config.instructions,
      audio: { output: { voice: config.voice || 'marin' } },
      delegation: { type: 'client' },
    },
    transport: { type: 'webrtc', sdp },
    client: { data_channel: { allowed_client_events: [] } },
  }
}

export async function exchangeSdp({
  fetchImpl,
  apiKey,
  config,
  sdp,
  liveHttpUrl = 'https://api.openai.com/v1/live/sessions',
  realtimeHttpUrl = 'https://api.openai.com/v1/realtime/calls',
  sidebandUrlFor,
}) {
  const model = String(config.model ?? '')
  if (model.startsWith('gpt-realtime')) {
    const form = new FormData()
    form.set('sdp', new Blob([sdp], { type: 'application/sdp' }), 'offer.sdp')
    form.set(
      'session',
      new Blob([JSON.stringify(realtimeSessionPayload(config, { omitAudioFormat: true }))], { type: 'application/json' }),
      'session.json',
    )
    const response = await fetchImpl(realtimeHttpUrl, {
      method: 'POST',
      headers: { Authorization: `Bearer ${apiKey ?? ''}` },
      body: form,
    })
    if (!response.ok) throw new Error('sdp_exchange')
    const answer = await response.text()
    const callId = String(response.headers.get('location') ?? '').split('/').filter(Boolean).pop() ?? ''
    if (!answer || !callId) throw new Error('sdp_exchange')
    return {
      answer,
      dialect: 'realtime',
      sidebandUrl: sidebandUrlFor
        ? sidebandUrlFor(callId)
        : `wss://api.openai.com/v1/realtime?call_id=${encodeURIComponent(callId)}`,
    }
  }

  const response = await fetchImpl(liveHttpUrl, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${apiKey ?? ''}`,
      'content-type': 'application/json',
    },
    body: JSON.stringify(liveCreateBody(config, sdp)),
  })
  if (!response.ok) throw new Error('sdp_exchange')
  const json = await response.json()
  const answer = json?.transport?.sdp
  const id = json?.session?.id
  if (!answer || !id) throw new Error('sdp_exchange')
  return {
    answer,
    dialect: 'live',
    sidebandUrl: sidebandUrlFor
      ? sidebandUrlFor(id)
      : `wss://api.openai.com/v1/live/sessions/${encodeURIComponent(id)}/sideband`,
  }
}
