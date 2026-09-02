import type { AIModel, Capability } from '@/types/ai-models'

/**
 * Speed-config model mixes: one-tap presets that point the user's default
 * models (chat, image, video, sound, vision, sorting) at a provider family.
 *
 * A mix never references a model BID — BIDs are install-local. Each slot lists
 * `service + providerId` candidates in priority order and the resolver picks
 * the first one this installation actually serves (`/api/v1/config/models`
 * only returns usable models, so presence implies a configured provider).
 * Slots that resolve to nothing are simply not written, which keeps a mix
 * honest on installs that lack a provider instead of failing the whole preset.
 *
 * The sorter stays on Groq gpt-oss-120b unless a mix explicitly overrides it:
 * the routing prompts are tuned against that model (see
 * DefaultModelConfigSeeder in the backend). Only the Google mix (Flash is a
 * proven JSON sorter) and the Europe mix (same gpt-oss-120b weights, hosted
 * locally on Ollama) opt out.
 */

export type ModelMixId = 'default' | 'openai' | 'anthropic' | 'google' | 'xai' | 'europe'

export type ModelMixIcon =
  /** The installation's brand icon (Synaplan bird unless white-labeled). */
  | { kind: 'brand' }
  /** A provider logo rendered through ServiceIcon. */
  | { kind: 'service'; service: string }
  /** A plain Iconify icon (e.g. the EU flag). */
  | { kind: 'iconify'; icon: string }

interface MixCandidate {
  service: string
  providerId: string
}

export interface ModelMixDefinition {
  id: ModelMixId
  icon: ModelMixIcon
  /**
   * The "Default Model Mix" restores the install's recommended defaults via
   * the dedicated reset endpoint instead of writing individual slots.
   */
  resetsToRecommended: boolean
  candidates: Partial<Record<Capability, MixCandidate[]>>
}

const openai = (providerId: string): MixCandidate => ({ service: 'OpenAI', providerId })
const anthropic = (providerId: string): MixCandidate => ({ service: 'Anthropic', providerId })
const google = (providerId: string): MixCandidate => ({ service: 'Google', providerId })
const xai = (providerId: string): MixCandidate => ({ service: 'xAI', providerId })
const mistral = (providerId: string): MixCandidate => ({ service: 'Mistral', providerId })
const ollama = (providerId: string): MixCandidate => ({ service: 'Ollama', providerId })
const trustedTokens = (providerId: string): MixCandidate => ({
  service: 'TrustedTokens',
  providerId,
})

export const MODEL_MIXES: ModelMixDefinition[] = [
  {
    id: 'default',
    icon: { kind: 'brand' },
    resetsToRecommended: true,
    candidates: {},
  },
  {
    id: 'openai',
    icon: { kind: 'service', service: 'OpenAI' },
    resetsToRecommended: false,
    candidates: {
      CHAT: [openai('gpt-5.6-sol'), openai('gpt-5.5'), openai('gpt-5.4')],
      ANALYZE: [openai('gpt-5.6-sol'), openai('gpt-5.5'), openai('gpt-5.4')],
      PIC2TEXT: [openai('gpt-5.6-sol'), openai('gpt-5.5'), openai('gpt-5.4')],
      TEXT2PIC: [openai('gpt-image-1.5'), openai('gpt-image-1')],
      PIC2PIC: [openai('gpt-image-1.5'), openai('gpt-image-1')],
      TEXT2SOUND: [openai('tts-1-hd'), openai('tts-1')],
      // OpenAI has no video model; Grok Imagine is the mix's stand-in.
      TEXT2VID: [xai('grok-imagine-video')],
    },
  },
  {
    id: 'anthropic',
    icon: { kind: 'service', service: 'Anthropic' },
    resetsToRecommended: false,
    // Anthropic ships no image/video/sound models — those slots keep the
    // user's current setting, and the panel subtitle only lists what is set.
    candidates: {
      CHAT: [
        anthropic('claude-fable-5-1'),
        anthropic('claude-fable-5'),
        anthropic('claude-sonnet-5'),
      ],
      ANALYZE: [
        anthropic('claude-fable-5-1'),
        anthropic('claude-fable-5'),
        anthropic('claude-sonnet-5'),
      ],
      PIC2TEXT: [
        anthropic('claude-fable-5-1'),
        anthropic('claude-fable-5'),
        anthropic('claude-sonnet-5'),
      ],
    },
  },
  {
    id: 'google',
    icon: { kind: 'service', service: 'Google' },
    resetsToRecommended: false,
    candidates: {
      CHAT: [google('gemini-3.1-pro-preview'), google('gemini-3.5-flash')],
      ANALYZE: [google('gemini-3.5-flash'), google('gemini-3.1-pro-preview')],
      // A Flash model is fast and JSON-reliable enough to take over sorting.
      SORT: [google('gemini-3.5-flash'), google('gemini-2.5-flash')],
      PIC2TEXT: [google('gemini-3.1-pro-preview'), google('gemini-3.5-flash')],
      TEXT2PIC: [
        google('gemini-3.1-flash-image-preview'),
        google('nano-banana-pro-preview'),
        google('gemini-2.5-flash-image'),
      ],
      PIC2PIC: [
        google('gemini-3.1-flash-image-preview'),
        google('nano-banana-pro-preview'),
        google('gemini-2.5-flash-image'),
      ],
      TEXT2VID: [google('veo-3.1-generate-preview'), google('veo-3.1-fast-generate-preview')],
      TEXT2SOUND: [google('gemini-2.5-flash-preview-tts')],
    },
  },
  {
    id: 'xai',
    icon: { kind: 'service', service: 'xAI' },
    resetsToRecommended: false,
    candidates: {
      CHAT: [xai('grok-4.6'), xai('grok-4.5')],
      ANALYZE: [xai('grok-4.6'), xai('grok-4.5')],
      PIC2TEXT: [xai('grok-4.6'), xai('grok-4.5')],
      TEXT2PIC: [xai('grok-imagine-image')],
      TEXT2VID: [xai('grok-imagine-video')],
      IMG2VID: [xai('grok-imagine-video-1.5')],
    },
  },
  {
    id: 'europe',
    icon: { kind: 'iconify', icon: 'circle-flags:european-union' },
    resetsToRecommended: false,
    // EU-jurisdiction / self-hosted models only. There is no European image or
    // video model in the catalog, so those slots stay untouched on purpose —
    // silently routing to a US provider would defeat the point of the mix.
    candidates: {
      CHAT: [
        ollama('gpt-oss:120b'),
        mistral('mistral-large-latest'),
        mistral('mistral-medium-latest'),
        trustedTokens('openai/gpt-oss-120b'),
        ollama('gpt-oss:20b'),
      ],
      ANALYZE: [mistral('mistral-medium-latest'), ollama('gpt-oss:120b'), ollama('gpt-oss:20b')],
      // Sorting must always move off the US default with this mix: the same
      // gpt-oss-120b weights the routing prompts are tuned for, either pulled
      // locally on Ollama or served by TrustedTokens (EU jurisdiction).
      SORT: [ollama('gpt-oss:120b'), trustedTokens('openai/gpt-oss-120b')],
      PIC2TEXT: [mistral('mistral-medium-latest')],
      TEXT2SOUND: [
        mistral('voxtral-mini-tts-2603'),
        { service: 'Piper', providerId: 'piper-multi' },
      ],
    },
  },
]

/** Order in which resolved model names appear in a mix subtitle. */
const SUBTITLE_CAPABILITY_ORDER: Capability[] = [
  'CHAT',
  'TEXT2PIC',
  'TEXT2VID',
  'IMG2VID',
  'TEXT2SOUND',
  'PIC2TEXT',
  'SORT',
  'ANALYZE',
  'PIC2PIC',
]

export interface ResolvedModelMix {
  id: ModelMixId
  icon: ModelMixIcon
  resetsToRecommended: boolean
  /** Capability -> model BID on this installation. Empty for the default mix. */
  defaults: Partial<Record<Capability, number>>
  /** Deduplicated display names for the subtitle, in capability order. */
  modelNames: string[]
  /** False when a preset cannot resolve a chat model on this installation. */
  available: boolean
}

const findCandidate = (
  capabilityModels: AIModel[] | undefined,
  candidates: MixCandidate[]
): AIModel | null => {
  if (!capabilityModels?.length) return null
  for (const candidate of candidates) {
    const match = capabilityModels.find(
      (model) =>
        model.available !== false &&
        model.service.toLowerCase() === candidate.service.toLowerCase() &&
        model.providerId === candidate.providerId
    )
    if (match) return match
  }
  return null
}

/** Vision twins share the base model's name plus a suffix; fold them together. */
const displayName = (model: AIModel): string => model.name.replace(/\s*\(vision\)$/i, '')

export function resolveModelMix(
  definition: ModelMixDefinition,
  models: Partial<Record<Capability, AIModel[]>>
): ResolvedModelMix {
  const defaults: Partial<Record<Capability, number>> = {}
  const names: string[] = []

  for (const capability of SUBTITLE_CAPABILITY_ORDER) {
    const candidates = definition.candidates[capability]
    if (!candidates) continue
    const match = findCandidate(models[capability], candidates)
    if (!match) continue
    defaults[capability] = match.id
    const name = displayName(match)
    if (!names.includes(name)) names.push(name)
  }

  return {
    id: definition.id,
    icon: definition.icon,
    resetsToRecommended: definition.resetsToRecommended,
    defaults,
    modelNames: names,
    available: definition.resetsToRecommended || defaults.CHAT !== undefined,
  }
}

export function resolveModelMixes(
  models: Partial<Record<Capability, AIModel[]>>
): ResolvedModelMix[] {
  return MODEL_MIXES.map((definition) => resolveModelMix(definition, models))
}

export function isModelMixId(value: unknown): value is ModelMixId {
  return typeof value === 'string' && MODEL_MIXES.some((mix) => mix.id === value)
}
