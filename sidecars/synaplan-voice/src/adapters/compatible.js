import { createRealtimeAdapter } from './realtime.js'

/**
 * OpenAI-Realtime-compatible socket: vLLM-Omni, Azure Realtime, Qwen Cloud.
 * The operator supplies the full websocket URL. Auth is bearer, an Azure
 * api-key header, or none for a private GPU box.
 */
export function compatibleHeaders(auth, apiKey) {
  const key = String(apiKey ?? '').trim()
  const mode = String(auth ?? 'bearer').trim().toLowerCase()
  if ('none' === mode || '' === key) return {}
  if ('api-key' === mode) return { 'api-key': key }
  return { Authorization: `Bearer ${key}` }
}

export function createCompatibleAdapter({
  url,
  apiKey,
  auth = 'bearer',
  model,
  WebSocketImpl,
} = {}) {
  const adapter = createRealtimeAdapter({
    url,
    headers: compatibleHeaders(auth, apiKey),
    WebSocketImpl,
  })
  const open = adapter.open.bind(adapter)
  adapter.open = (config) => {
    const override = String(model ?? '').trim()
    return open(override === '' ? config : { ...config, model: override })
  }
  return adapter
}
