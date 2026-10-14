import { createRealtimeAdapter } from './realtime.js'

/**
 * xAI Grok Voice speaks the OpenAI Realtime dialect.
 * The provider does not send a usage tick. Text items are counted here.
 * The session length is the gateway clock, because server VAD bills duration.
 */
export function createXaiAdapter({ apiKey, url, WebSocketImpl } = {}) {
  return createRealtimeAdapter({
    apiKey,
    url: url ?? ((config) => `wss://api.x.ai/v1/realtime?model=${encodeURIComponent(config.model ?? '')}`),
    WebSocketImpl,
    countTextItems: true,
  })
}
