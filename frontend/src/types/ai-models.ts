/**
 * AI Model Types
 */

export type Capability =
  | 'SORT'
  | 'CHAT'
  /** Phase 2d: dedicated memory-extraction model. Routes through Groq by default for low-latency post-stream processing. */
  | 'MEM'
  | 'VECTORIZE'
  | 'PIC2TEXT'
  | 'TEXT2PIC'
  | 'PIC2PIC'
  | 'TEXT2VID'
  /** Image-to-video: animate an attached image. Shares the text2vid BTAG; surfaced as its own default slot (mirrors PIC2PIC over text2pic). */
  | 'IMG2VID'
  | 'SOUND2TEXT'
  | 'TEXT2SOUND'
  | 'ANALYZE'

/**
 * Why a model cannot be used on this installation:
 * - provider_unavailable: the provider has no API key / base URL configured
 * - not_pulled: the Ollama model is not pulled on the local server
 */
export type ModelUnavailableReason = 'provider_unavailable' | 'not_pulled'

export interface AIModel {
  id: number
  service: string
  name: string
  tag: string
  providerId: string
  quality: number
  rating: number
  priceIn: number
  priceOut: number
  selectable?: boolean // Not returned by backend getModels
  description: string | null
  isSystemModel: boolean
  features: string[]
  /** Only false in admin views requested with includeUnavailable; regular responses contain available models only. */
  available?: boolean
  unavailableReason?: ModelUnavailableReason | null
}

/** Per-provider availability of this installation, from /config/models. */
export interface ProviderAvailability {
  name: string
  displayName: string
  available: boolean
  /** True for cloud providers configured via a platform API key; false for URL/local providers (Ollama, custom endpoints). */
  requiresKey: boolean
}

export interface AgainData {
  eligible: AIModel[]
  predictedNext: AIModel | null
  tag: string
  current_model_id?: number | null
  currentModelId?: number | null
}

export interface MessageResponse {
  success: boolean
  message: {
    id: number
    text: string
    hasFile: boolean
    filePath: string
    fileType: string
    provider: string
    timestamp: number
    trackId: number
    topic: string
  }
}
