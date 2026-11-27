import { realtimeSessionPayload } from './adapters/realtime.js'

/**
 * Seconds without an input or output transcript before a GPT-Live WebRTC
 * session pauses inference and drops to the standby rate (OpenAI allows
 * 5 to 60). Long enough that a Synaplan answer, which takes several seconds,
 * is spoken before the session pauses.
 */
export const LIVE_IDLE_TIMEOUT_S = 30

/**
 * Server-side SDP exchange. The browser never sees the provider key.
 * GPT-Live forbids every client event on the untrusted data channel.
 * GPT-Realtime has no equivalent switch; the app still does not open
 * that channel for control. Audio is Opus on the peer connection.
 *
 * The speech wake policy keeps a GPT-Live session on standby until the
 * person speaks, and returns it to standby after LIVE_IDLE_TIMEOUT_S of
 * silence. Standby is billed at the lower rate; the sideband reports it
 * as session.paused and session.resumed.
 */
export function liveCreateBody(config, sdp) {
  return {
    session: {
      model: config.model,
      store: false,
      instructions: config.instructions,
      audio: { output: { voice: config.voice || 'marin' } },
      delegation: { type: 'client' },
      wake_policy: { type: 'speech' },
      idle_timeout_s: LIVE_IDLE_TIMEOUT_S,
      client: { data_channel: { allowed_client_events: [] } },
    },
    transport: { type: 'webrtc', sdp },
  }
}

/**
 * The provider's refusal, without its body: status plus OpenAI's error
 * code and param, so the log says why the call failed. Never the key.
 */
async function refusal(response) {
  const error = new Error('sdp_exchange')
  error.status = response.status
  try {
    const body = await response.json()
    error.code = body?.error?.code ?? body?.error?.type ?? null
    error.param = body?.error?.param ?? null
  } catch {
    error.code = null
  }
  return error
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
    if (!response.ok) throw await refusal(response)
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
  if (!response.ok) throw await refusal(response)
  const json = await response.json()
  const answer = json?.transport?.sdp
  const id = json?.session?.id
  if (!answer || !id) throw new Error('sdp_exchange')
  return {
    answer,
    dialect: 'live',
    sidebandUrl: sidebandUrlFor
      ? sidebandUrlFor(id)
      : `wss://api.openai.com/v1/live/sessions/${encodeURIComponent(id)}/attach`,
  }
}
