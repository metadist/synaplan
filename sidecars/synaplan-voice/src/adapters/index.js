import { createGeminiAdapter } from './gemini.js'
import { createMockAdapter } from './mock.js'
import { createOpenAiAdapter } from './openai.js'
import { createOpenAiRealtimeAdapter } from './realtime.js'
import { createXaiAdapter } from './xai.js'

export function adapterKind(provider, model = '') {
  if (provider === 'mock-openai' || provider === 'mock-google') return provider
  if (provider === 'google') return 'gemini'
  if (provider === 'xai') return 'xai'
  if (provider === 'openai' && String(model).startsWith('gpt-realtime')) return 'openai-realtime'
  return 'openai-live'
}

export function adapterFor(provider, env = process.env, model = '') {
  const kind = adapterKind(provider, model)
  if (kind === 'mock-openai' || kind === 'mock-google') return createMockAdapter(provider)
  if (kind === 'gemini') {
    return createGeminiAdapter({ apiKey: env.GOOGLE_GEMINI_API_KEY ?? '', url: env.GEMINI_LIVE_URL })
  }
  if (kind === 'xai') {
    return createXaiAdapter({
      apiKey: env.XAI_API_KEY ?? '',
      url: env.XAI_REALTIME_URL || undefined,
    })
  }
  if (kind === 'openai-realtime') {
    return createOpenAiRealtimeAdapter({
      apiKey: env.OPENAI_API_KEY ?? '',
      url: env.OPENAI_REALTIME_URL || undefined,
    })
  }
  return createOpenAiAdapter({
    apiKey: env.OPENAI_API_KEY ?? '',
    region: env.OPENAI_LIVE_REGION ?? 'us',
    url: env.OPENAI_LIVE_URL,
  })
}
