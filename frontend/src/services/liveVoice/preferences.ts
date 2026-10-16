import { ref } from 'vue'

const CAPTIONS_KEY = 'synaplan.liveVoice.captions'
const VOICES_KEY = 'synaplan.liveVoice.voices'
const MODELS_KEY = 'models'

function readCaptionsPreferred(): boolean {
  try {
    return localStorage.getItem(CAPTIONS_KEY) !== '0'
  } catch {
    return true
  }
}

/** Shared with the live conversation and the voice settings, so a change applies immediately. */
const captionsOn = ref(readCaptionsPreferred())

export function captionsPreferred(): boolean {
  return captionsOn.value
}

export function captionsPreferredRef() {
  return captionsOn
}

export function setCaptionsPreferred(on: boolean): void {
  captionsOn.value = on
  try {
    localStorage.setItem(CAPTIONS_KEY, on ? '1' : '0')
  } catch {
    // A private window can refuse storage. The toggle still works for this visit.
  }
}

function readVoiceStore(): Record<string, unknown> {
  try {
    const raw = localStorage.getItem(VOICES_KEY)
    if (!raw) return {}
    const parsed = JSON.parse(raw) as unknown
    if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) return {}
    return parsed as Record<string, unknown>
  } catch {
    return {}
  }
}

function modelVoices(store: Record<string, unknown>): Record<string, unknown> {
  const models = store[MODELS_KEY]
  if (models === null || typeof models !== 'object' || Array.isArray(models)) return {}
  return models as Record<string, unknown>
}

/**
 * The saved voice for this model. An older choice stored under the provider
 * still applies when that voice is in this model's list.
 */
export function preferredVoice(
  modelId: number | null,
  provider: string,
  allowed: readonly string[]
): string {
  const store = readVoiceStore()
  if (modelId !== null) {
    const voice = modelVoices(store)[String(modelId)]
    if (typeof voice === 'string' && allowed.includes(voice)) return voice
  }
  const legacy = store[provider]
  return typeof legacy === 'string' && allowed.includes(legacy) ? legacy : ''
}

export function setPreferredVoice(modelId: number, voice: string): void {
  try {
    const store = readVoiceStore()
    const models = { ...modelVoices(store), [String(modelId)]: voice }
    store[MODELS_KEY] = models
    localStorage.setItem(VOICES_KEY, JSON.stringify(store))
  } catch {
    // Same as captions: the choice still applies to the conversation being started.
  }
}

/** Saved voice when it belongs to this model, otherwise the model's own default. */
export function resolveLiveVoice(
  modelId: number | null,
  provider: string,
  voices: readonly string[],
  fallback = ''
): string {
  const stored = preferredVoice(modelId, provider, voices)
  if (stored !== '') return stored
  if (fallback !== '' && voices.includes(fallback)) return fallback
  return voices[0] ?? ''
}
